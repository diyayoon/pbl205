param (
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-zA-Z0-9._-]{3,50}$')]
    [string]$Username,

    [Parameter(Mandatory = $true)]
    [ValidateSet('superadmin', 'admin_divisi')]
    [string]$RequesterRole
)

$ErrorActionPreference = 'Stop'

# ============================================================
# KONFIGURASI DOMAIN PBL205
# ============================================================
$DomainDN = 'DC=pbl205,DC=local'
$BaseOU   = "OU=Departments,$DomainDN"

$DivisionMap = @{
    'FINANCE' = @{
        OU          = "OU=FINANCE,$BaseOU"
        MemberGroup = 'GG_FINANCE_Member'
        AdminGroup  = 'GG_FINANCE_Admin'
    }
    'HR' = @{
        OU          = "OU=HR,$BaseOU"
        MemberGroup = 'GG_HR_Member'
        AdminGroup  = 'GG_HR_Admin'
    }
    'IT' = @{
        OU          = "OU=IT,$BaseOU"
        MemberGroup = 'GG_IT_Member'
        AdminGroup  = 'GG_IT_Admin'
    }
}

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

try {
    Import-Module ActiveDirectory

    # ============================================================
    # CEK USER ADA DI AD
    # ============================================================
    $targetUser = Get-ADUser -Filter "SamAccountName -eq '$Username'" -Properties MemberOf, DistinguishedName -ErrorAction SilentlyContinue

    if (-not $targetUser) {
        Return-Json -Success $true -Message "User '$Username' tidak ditemukan di AD, dianggap sudah tidak ada." -Data @{
            username = $Username
            skipped  = $true
        }
        exit 0
    }

    # ============================================================
    # VALIDASI AKSES: admin_divisi tidak boleh hapus admin lain
    # ============================================================
    if ($RequesterRole -eq 'admin_divisi') {
        $isTargetAdmin = $false

        foreach ($div in $DivisionMap.Keys) {
            $adminGroup = $DivisionMap[$div].AdminGroup
            $group = Get-ADGroup -Filter "SamAccountName -eq '$adminGroup'" -ErrorAction SilentlyContinue

            if ($group) {
                $members = Get-ADGroupMember -Identity $adminGroup -ErrorAction SilentlyContinue
                if ($members | Where-Object { $_.SamAccountName -eq $Username }) {
                    $isTargetAdmin = $true
                    break
                }
            }
        }

        if ($isTargetAdmin) {
            throw 'Admin divisi tidak boleh menghapus akun admin lain.'
        }
    }

    # ============================================================
    # HAPUS USER DARI AD
    # ============================================================
    Remove-ADUser -Identity $targetUser.SamAccountName -Confirm:$false

    Return-Json -Success $true -Message "User '$Username' berhasil dihapus dari Active Directory." -Data @{
        username           = $Username
        distinguished_name = $targetUser.DistinguishedName
    }

    exit 0
}
catch {
    Return-Json -Success $false -Message $_.Exception.Message -Data @{
        username       = $Username
        requester_role = $RequesterRole
    }

    exit 1
}
