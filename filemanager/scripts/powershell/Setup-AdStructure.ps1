# SCRIPT UNTUK BUAT OU DAN GROUP YANG DI PAKAI Create-AdUserFromWeb.ps1.

param(
    [string]$DomainName = 'pbl.local',
    [string]$BaseOuName = 'PBL-Users'
)

$ErrorActionPreference = 'Stop'
Import-Module ActiveDirectory

$DomainDN = (($DomainName -split '\.') | ForEach-Object { "DC=$_" }) -join ','
$BaseOU = "OU=$BaseOuName,$DomainDN"

function Ensure-OU {
    param([string]$Name, [string]$Path)
    $dn = "OU=$Name,$Path"
    if (-not (Get-ADOrganizationalUnit -LDAPFilter "(distinguishedName=$dn)" -ErrorAction SilentlyContinue)) {
        New-ADOrganizationalUnit -Name $Name -Path $Path -ProtectedFromAccidentalDeletion $false | Out-Null
        Write-Host "Created OU: $dn"
    } else {
        Write-Host "OU exists: $dn"
    }
}

function Ensure-Group {
    param([string]$Name, [string]$Path)
    if (-not (Get-ADGroup -Filter "Name -eq '$Name'" -ErrorAction SilentlyContinue)) {
        New-ADGroup -Name $Name -GroupScope Global -GroupCategory Security -Path $Path | Out-Null
        Write-Host "Created group: $Name"
    } else {
        Write-Host "Group exists: $Name"
    }
}

Ensure-OU -Name $BaseOuName -Path $DomainDN

$divisions = @(
    @{ Ou='Finance';   Prefix='Finance' },
    @{ Ou='HR';        Prefix='HR' },
    @{ Ou='IT';        Prefix='IT' },
    @{ Ou='Marketing'; Prefix='Marketing' },
    @{ Ou='Legal';     Prefix='Legal' }
)

foreach ($d in $divisions) {
    Ensure-OU -Name $d.Ou -Path $BaseOU
    $ouPath = "OU=$($d.Ou),$BaseOU"
    Ensure-Group -Name "GG_$($d.Prefix)_Admin"  -Path $ouPath
    Ensure-Group -Name "GG_$($d.Prefix)_Member" -Path $ouPath
}

Write-Host "AD structure ready under $BaseOU"
