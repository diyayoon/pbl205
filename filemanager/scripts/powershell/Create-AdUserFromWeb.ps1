param (
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-zA-Z0-9._-]{3,50}$')]
    [string]$Username,

    [Parameter(Mandatory = $true)]
    [ValidateLength(2,150)]
    [string]$FullName,

    [Parameter(Mandatory = $true)]
    [ValidateLength(8,128)]
    [string]$Password,

    [Parameter(Mandatory = $true)]
    [ValidateSet('anggota', 'admin_divisi')]
    [string]$NewUserRole,

    [Parameter(Mandatory = $true)]
    [ValidateSet('FINANCE', 'Finance', 'HR', 'IT')]
    [string]$NewUserDivision,

    [Parameter(Mandatory = $true)]
    [ValidateSet('superadmin', 'admin_divisi')]
    [string]$RequesterRole,

    [ValidateSet('', 'FINANCE', 'Finance', 'HR', 'IT')]
    [string]$RequesterDivision = '',

    [ValidateSet('true', 'false')]
    [string]$ProvisionMemberFolder = 'false',

    [string]$MemberFolderName = '',

    [ValidateSet('', 'FINANCE', 'HR', 'IT')]
    [string]$DivisionShare = '',

    # Credential SMB/Samba yang dikirim dari docker-compose.yml.
    # Dipakai untuk menghindari WinRM double-hop saat remote PowerShell membuat folder UNC.
    [string]$SambaDomain = '',

    [string]$SambaUser = '',

    [string]$SambaPassword = '',

    [ValidateSet('true', 'false')]
    [string]$SetMemberFolderAcl = 'false',

    [ValidateSet('true', 'false')]
    [string]$FailOnAclError = 'false'
)

$ErrorActionPreference = 'Stop'

# ============================================================
# KONFIGURASI DOMAIN PBL205
# Group dan folder/share departemen dianggap SUDAH ADA.
# Ubah lewat environment variable jika nama/path di lab berbeda.
# ============================================================
$DomainName    = if ($env:PBL_DOMAIN_NAME) { $env:PBL_DOMAIN_NAME } else { 'pbl205.local' }
$DomainDN      = if ($env:PBL_DOMAIN_DN)   { $env:PBL_DOMAIN_DN }   else { 'DC=pbl205,DC=local' }
$BaseOU        = if ($env:PBL_BASE_OU)     { $env:PBL_BASE_OU }     else { "OU=Departments,$DomainDN" }
$NetBIOSDomain = if ($env:PBL_NETBIOS_DOMAIN) { $env:PBL_NETBIOS_DOMAIN } else { 'PBL205' }

$DivisionMap = @{
    'FINANCE' = @{
        OU           = if ($env:PBL_FINANCE_OU) { $env:PBL_FINANCE_OU } else { "OU=FINANCE,$BaseOU" }
        MemberGroup  = if ($env:PBL_FINANCE_MEMBER_GROUP) { $env:PBL_FINANCE_MEMBER_GROUP } else { 'GG_FINANCE_Member' }
        AdminGroup   = if ($env:PBL_FINANCE_ADMIN_GROUP)  { $env:PBL_FINANCE_ADMIN_GROUP }  else { 'GG_FINANCE_Admin' }
        SharePath    = if ($env:PBL_FINANCE_SHARE_PATH)   { $env:PBL_FINANCE_SHARE_PATH }   else { '\\sambafs.pbl205.local\FINANCE' }
    }
    'HR' = @{
        OU           = if ($env:PBL_HR_OU) { $env:PBL_HR_OU } else { "OU=HR,$BaseOU" }
        MemberGroup  = if ($env:PBL_HR_MEMBER_GROUP) { $env:PBL_HR_MEMBER_GROUP } else { 'GG_HR_Member' }
        AdminGroup   = if ($env:PBL_HR_ADMIN_GROUP)  { $env:PBL_HR_ADMIN_GROUP }  else { 'GG_HR_Admin' }
        SharePath    = if ($env:PBL_HR_SHARE_PATH)   { $env:PBL_HR_SHARE_PATH }   else { '\\sambafs.pbl205.local\HR' }
    }
    'IT' = @{
        OU           = if ($env:PBL_IT_OU) { $env:PBL_IT_OU } else { "OU=IT,$BaseOU" }
        MemberGroup  = if ($env:PBL_IT_MEMBER_GROUP) { $env:PBL_IT_MEMBER_GROUP } else { 'GG_IT_Member' }
        AdminGroup   = if ($env:PBL_IT_ADMIN_GROUP)  { $env:PBL_IT_ADMIN_GROUP }  else { 'GG_IT_Admin' }
        SharePath    = if ($env:PBL_IT_SHARE_PATH)   { $env:PBL_IT_SHARE_PATH }   else { '\\sambafs.pbl205.local\IT' }
    }
}

# Variabel ini dipakai di catch supaya error dari web tidak lagi generik.
$Stage = 'inisialisasi'
$CreatedInThisRun = $false
$TargetOU = $null
$TargetGroup = $null
$ResolvedSharePath = $null
$DivisionKey = $NewUserDivision
$DivisionShareKey = $DivisionShare
$ExistingUserWasReused = $false

function Return-Json {
    param (
        [bool]$Success,
        [string]$Message,
        [object]$Data = $null
    )

    $result = [ordered]@{
        success = $Success
        message = $Message
        data    = $Data
    }

    $result | ConvertTo-Json -Depth 8 -Compress
}

function Normalize-Division {
    param([string]$Division)

    if ([string]::IsNullOrWhiteSpace($Division)) {
        return ''
    }

    $upper = $Division.Trim().ToUpperInvariant()

    if ($upper -eq 'FINANCE') { return 'FINANCE' }
    if ($upper -eq 'HR')      { return 'HR' }
    if ($upper -eq 'IT')      { return 'IT' }

    throw "Divisi '$Division' tidak valid. Gunakan FINANCE, HR, atau IT."
}

function Assert-AdGroupExists {
    param([Parameter(Mandatory = $true)][string]$GroupName)

    $group = Get-ADGroup -Filter "SamAccountName -eq '$GroupName'" -ErrorAction Stop
    if (-not $group) {
        throw "Group '$GroupName' belum ada di Active Directory. Buat group departemen di DC terlebih dahulu; script web tidak membuat group utama."
    }
    return $group
}

function Add-AdGroupMemberIfNeeded {
    param(
        [Parameter(Mandatory = $true)][string]$GroupName,
        [Parameter(Mandatory = $true)][string]$MemberSam
    )

    try {
        $alreadyMember = Get-ADGroupMember -Identity $GroupName -Recursive -ErrorAction Stop |
            Where-Object { $_.SamAccountName -eq $MemberSam } |
            Select-Object -First 1

        if (-not $alreadyMember) {
            Add-ADGroupMember -Identity $GroupName -Members $MemberSam -ErrorAction Stop
        }
    }
    catch {
        # Kalau race condition / retry membuat user sudah jadi member, jangan dianggap gagal.
        if ($_.Exception.Message -match 'already.*member|sudah.*anggota') {
            return
        }
        throw
    }
}

function Sanitize-FolderName {
    param([string]$Name)
    $clean = ($Name -replace '[<>:"/\\|?*\x00-\x1F]', '_').Trim('. ')
    if ([string]::IsNullOrWhiteSpace($clean)) { return 'folder' }
    return $clean
}

function New-SambaCredential {
    param(
        [string]$Domain,
        [string]$User,
        [string]$Password
    )

    if ([string]::IsNullOrWhiteSpace($User) -or [string]::IsNullOrWhiteSpace($Password)) {
        return $null
    }

    $identity = $User.Trim()
    if ($identity -notmatch '\\' -and -not [string]::IsNullOrWhiteSpace($Domain)) {
        $identity = "$($Domain.Trim())\$identity"
    }

    $secure = ConvertTo-SecureString $Password -AsPlainText -Force
    return New-Object System.Management.Automation.PSCredential($identity, $secure)
}

function Add-FileSystemRuleSafe {
    param(
        [System.Security.AccessControl.DirectorySecurity]$Acl,
        [string]$Identity,
        [System.Security.AccessControl.FileSystemRights]$Rights
    )

    $rule = New-Object System.Security.AccessControl.FileSystemAccessRule(
        $Identity,
        $Rights,
        'ContainerInherit,ObjectInherit',
        'None',
        'Allow'
    )
    $Acl.AddAccessRule($rule) | Out-Null
}

function Ensure-MemberFolder {
    param(
        [Parameter(Mandatory = $true)][string]$RootSharePath,
        [Parameter(Mandatory = $true)][string]$FolderName,
        [Parameter(Mandatory = $true)][string]$MemberSam,
        [Parameter(Mandatory = $true)][string]$AdminGroupName,
        [Parameter(Mandatory = $true)][string]$NetBIOSDomain,
        [System.Management.Automation.PSCredential]$SambaCredential = $null
    )

    $safeFolderName = Sanitize-FolderName -Name $FolderName
    $returnFolderPath = Join-Path -Path $RootSharePath -ChildPath $safeFolderName
    $accessRootPath = $RootSharePath
    $driveName = $null

    try {
        if ($null -ne $SambaCredential) {
            # Mapping share dengan credential eksplisit dari docker-compose.yml.
            # Ini mencegah masalah WinRM double-hop saat akses \\sambafs dari DC.
            $driveName = 'PBL' + ([guid]::NewGuid().ToString('N').Substring(0, 8))
            New-PSDrive -Name $driveName -PSProvider FileSystem -Root $RootSharePath -Credential $SambaCredential -ErrorAction Stop | Out-Null
            $accessRootPath = "${driveName}:\"
        }

        if (-not (Test-Path -LiteralPath $accessRootPath)) {
            throw "Folder/share utama departemen tidak ditemukan atau credential tidak punya akses: $RootSharePath"
        }

        $memberFolderAccessPath = Join-Path -Path $accessRootPath -ChildPath $safeFolderName

        # UNC path langsung — dipakai khusus untuk Get-Acl/Set-Acl karena kedua cmdlet itu
        # tidak reliable pakai PSDrive path di konteks WinRM/remoting session.
        # PSDrive ($memberFolderAccessPath) tetap dipakai untuk operasi filesystem biasa (Test-Path, New-Item).
        $memberFolderAclPath = Join-Path -Path $RootSharePath -ChildPath $safeFolderName

        if (-not (Test-Path -LiteralPath $memberFolderAccessPath)) {
            New-Item -ItemType Directory -Path $memberFolderAccessPath -Force -ErrorAction Stop | Out-Null

            # Setelah New-Item, kadang share SMB butuh waktu sebentar sebelum folder baru
            # "kelihatan" lewat operasi berikutnya. Polling sampai Test-Path konsisten.
            $folderReadyRetries = 0
            while (-not (Test-Path -LiteralPath $memberFolderAccessPath) -and $folderReadyRetries -lt 5) {
                Start-Sleep -Milliseconds 500
                $folderReadyRetries++
            }
        }

        $aclWarning = $null

        if ($SetMemberFolderAcl.ToLowerInvariant() -eq 'true') {
            try {
                # Get-Acl/Set-Acl pakai UNC path ($memberFolderAclPath), bukan PSDrive path,
                # supaya PowerShell resolve path-nya dengan benar di konteks WinRM.
                $acl = $null
                $aclRetries = 0
                $lastAclError = $null
                while ($null -eq $acl -and $aclRetries -lt 5) {
                    try {
                        $acl = Get-Acl -LiteralPath $memberFolderAclPath -ErrorAction Stop
                    }
                    catch {
                        $lastAclError = $_
                        $aclRetries++
                        if ($aclRetries -lt 5) { Start-Sleep -Milliseconds 500 }
                    }
                }
                if ($null -eq $acl) { throw $lastAclError }

                $acl.SetAccessRuleProtection($true, $false)

                Add-FileSystemRuleSafe -Acl $acl -Identity 'SYSTEM' -Rights 'FullControl'
                Add-FileSystemRuleSafe -Acl $acl -Identity "$NetBIOSDomain\Domain Admins" -Rights 'FullControl'
                Add-FileSystemRuleSafe -Acl $acl -Identity "$NetBIOSDomain\$AdminGroupName" -Rights 'FullControl'
                Add-FileSystemRuleSafe -Acl $acl -Identity "$NetBIOSDomain\$MemberSam" -Rights 'Modify'

                Set-Acl -LiteralPath $memberFolderAclPath -AclObject $acl -ErrorAction Stop
            }
            catch {
                $aclWarning = "Folder berhasil dibuat, tetapi ACL gagal diset: $($_.Exception.Message)"
                if ($FailOnAclError.ToLowerInvariant() -eq 'true') {
                    throw $aclWarning
                }
            }
        }

        return @{
            folder_path = $returnFolderPath
            acl_warning = $aclWarning
            used_explicit_samba_credential = ($null -ne $SambaCredential)
        }
    }
    finally {
        if (-not [string]::IsNullOrWhiteSpace($driveName)) {
            Remove-PSDrive -Name $driveName -Force -ErrorAction SilentlyContinue
        }
    }
}

try {
    $Stage = 'import module ActiveDirectory'
    Import-Module ActiveDirectory -ErrorAction Stop

    $Stage = 'menyiapkan credential Samba'
    $SambaCredential = New-SambaCredential -Domain $SambaDomain -User $SambaUser -Password $SambaPassword

    $Stage = 'normalisasi divisi'
    $DivisionKey = Normalize-Division -Division $NewUserDivision
    $RequesterDivisionKey = Normalize-Division -Division $RequesterDivision
    $DivisionShareKey = Normalize-Division -Division $DivisionShare
    if ([string]::IsNullOrWhiteSpace($DivisionShareKey)) { $DivisionShareKey = $DivisionKey }

    if (-not $DivisionMap.ContainsKey($DivisionKey)) {
        throw "Divisi '$DivisionKey' tidak ditemukan pada DivisionMap."
    }

    # ============================================================
    # VALIDASI AKSES BERDASARKAN ROLE WEBSITE
    # ============================================================
    $Stage = 'validasi role requester'
    if ($RequesterRole -eq 'admin_divisi') {
        if ([string]::IsNullOrWhiteSpace($RequesterDivisionKey)) {
            throw 'RequesterDivision wajib diisi untuk admin_divisi.'
        }

        if ($NewUserRole -ne 'anggota') {
            throw 'Admin divisi hanya boleh membuat user dengan role anggota.'
        }

        if ($DivisionKey -ne $RequesterDivisionKey) {
            throw 'Admin divisi hanya boleh membuat anggota di divisinya sendiri.'
        }
    }

    # ============================================================
    # CEK OU DIVISI DAN GROUP EXISTING
    # ============================================================
    $Stage = 'validasi OU dan group divisi'
    $TargetOU = $DivisionMap[$DivisionKey].OU
    Get-ADOrganizationalUnit -Identity $TargetOU -ErrorAction Stop | Out-Null

    if ($NewUserRole -eq 'admin_divisi') {
        $TargetGroup = $DivisionMap[$DivisionKey].AdminGroup
    }
    else {
        $TargetGroup = $DivisionMap[$DivisionKey].MemberGroup
    }

    Assert-AdGroupExists -GroupName $DivisionMap[$DivisionKey].MemberGroup | Out-Null
    Assert-AdGroupExists -GroupName $DivisionMap[$DivisionKey].AdminGroup | Out-Null
    Assert-AdGroupExists -GroupName $TargetGroup | Out-Null

    # ============================================================
    # CEK USER SUDAH ADA ATAU BELUM
    # Retry setelah kegagalan parsial boleh lanjut: kalau user sudah ada di AD,
    # script akan lanjut memastikan group dan folder, bukan langsung gagal.
    # ============================================================
    $Stage = 'cek user existing di AD'
    $existingUser = Get-ADUser -Filter "SamAccountName -eq '$Username'" -ErrorAction Stop

    if ($existingUser) {
        $ExistingUserWasReused = $true
    }
    else {
        # ============================================================
        # CREATE USER AD
        # ============================================================
        $Stage = 'membuat user Active Directory'
        $SecurePassword = ConvertTo-SecureString $Password -AsPlainText -Force
        $UPN = "$Username@$DomainName"

        New-ADUser `
            -Name $FullName `
            -SamAccountName $Username `
            -UserPrincipalName $UPN `
            -DisplayName $FullName `
            -Path $TargetOU `
            -AccountPassword $SecurePassword `
            -Enabled $true `
            -ChangePasswordAtLogon $true `
            -Description "Created automatically from PBL205 web system" `
            -ErrorAction Stop | Out-Null

        $CreatedInThisRun = $true
    }

    # ============================================================
    # MASUKKAN USER KE GROUP DIVISI EXISTING
    # ============================================================
    $Stage = "menambahkan user ke group '$TargetGroup'"
    Add-AdGroupMemberIfNeeded -GroupName $TargetGroup -MemberSam $Username

    # Kalau user adalah admin divisi, dia juga dimasukkan ke group member
    # supaya tetap mendapat akses resource dasar divisinya.
    if ($NewUserRole -eq 'admin_divisi') {
        $memberGroupForAdmin = $DivisionMap[$DivisionKey].MemberGroup
        $Stage = "menambahkan admin divisi ke group '$memberGroupForAdmin'"
        Add-AdGroupMemberIfNeeded -GroupName $memberGroupForAdmin -MemberSam $Username
    }

    # ============================================================
    # BUAT FOLDER PERSONAL ANGGOTA DI SHARE EXISTING
    # ============================================================
    $MemberFolderResult = $null
    if ($NewUserRole -eq 'anggota' -and $ProvisionMemberFolder.ToLowerInvariant() -eq 'true') {
        $folderNameToCreate = if ([string]::IsNullOrWhiteSpace($MemberFolderName)) { $Username } else { $MemberFolderName }
        $ResolvedSharePath = $DivisionMap[$DivisionShareKey].SharePath
        $Stage = "membuat folder anggota di share '$ResolvedSharePath'"
        $MemberFolderResult = Ensure-MemberFolder `
            -RootSharePath $ResolvedSharePath `
            -FolderName $folderNameToCreate `
            -MemberSam $Username `
            -AdminGroupName $DivisionMap[$DivisionKey].AdminGroup `
            -NetBIOSDomain $NetBIOSDomain `
            -SambaCredential $SambaCredential
    }

    $Stage = 'mengambil hasil user AD'
    $CreatedUser = Get-ADUser -Identity $Username -Properties DistinguishedName, UserPrincipalName, MemberOf -ErrorAction Stop

    Return-Json -Success $true -Message 'User berhasil dibuat/disinkronkan di Active Directory dan folder anggota diproses sesuai role.' -Data @{
        username                = $Username
        full_name               = $FullName
        role                    = $NewUserRole
        division                = $DivisionKey
        user_principal          = $CreatedUser.UserPrincipalName
        distinguished_name      = $CreatedUser.DistinguishedName
        target_ou               = $TargetOU
        group                   = $TargetGroup
        member_folder           = $MemberFolderResult
        existing_user_reused    = $ExistingUserWasReused
        created_in_this_run     = $CreatedInThisRun
    }

    exit 0
}
catch {
    $rollbackMessage = $null
    $rollbackOnFail = if ($env:PBL_ROLLBACK_AD_ON_FAIL) { $env:PBL_ROLLBACK_AD_ON_FAIL } else { 'true' }

    # Kalau user baru sempat dibuat oleh script ini lalu step berikutnya gagal,
    # hapus lagi supaya AD dan database website tidak beda/nyangkut.
    if ($CreatedInThisRun -and $rollbackOnFail.ToLowerInvariant() -eq 'true') {
        try {
            Remove-ADUser -Identity $Username -Confirm:$false -ErrorAction Stop
            $rollbackMessage = 'Rollback berhasil: user AD yang baru dibuat sudah dihapus karena proses provisioning gagal.'
        }
        catch {
            $rollbackMessage = "Rollback gagal: $($_.Exception.Message)"
        }
    }

    Return-Json -Success $false -Message "Gagal pada tahap '$Stage': $($_.Exception.Message)" -Data @{
        username             = $Username
        role                 = $NewUserRole
        division             = $NewUserDivision
        normalized_division  = $DivisionKey
        requester_role       = $RequesterRole
        requester_division   = $RequesterDivision
        target_ou            = $TargetOU
        target_group         = $TargetGroup
        share_path           = $ResolvedSharePath
        created_in_this_run  = $CreatedInThisRun
        existing_user_reused = $ExistingUserWasReused
        rollback             = $rollbackMessage
        stage                = $Stage
    }

    exit 1
}
