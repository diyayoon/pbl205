import base64
import json
import os
import sys
from pypsrp.client import Client


def fail(message, data=None):
    result = {
        "success": False,
        "message": message
    }

    if data is not None:
        result["data"] = data

    print(json.dumps(result, ensure_ascii=False))
    sys.exit(1)


def normalize_output(output):
    if output is None:
        return ""

    if isinstance(output, str):
        return output.strip()

    try:
        return "\n".join(str(item) for item in output).strip()
    except Exception:
        return str(output).strip()


def normalize_error_stream(streams):
    errors = []

    try:
        for err in streams.error:
            parts = []

            for attr in ["message", "exception", "error_details", "script_stack_trace"]:
                try:
                    value = getattr(err, attr, None)
                    if value:
                        parts.append(str(value))
                except Exception:
                    pass

            if parts:
                errors.append(" | ".join(parts))
            else:
                errors.append(str(err))
    except Exception:
        try:
            errors.append(str(streams.error))
        except Exception:
            errors.append("Unknown PowerShell error")

    error_text = " | ".join(errors).strip()
    return error_text if error_text else "Unknown PowerShell error"


def print_success_from_output(output_text):
    output_text = output_text.strip()

    if not output_text:
        fail("PowerShell tidak mengembalikan output.")

    try:
        parsed = json.loads(output_text)
        print(json.dumps(parsed, ensure_ascii=False))
        sys.exit(0 if parsed.get("success") else 1)
    except Exception:
        print(json.dumps({
            "success": True,
            "message": "PowerShell selesai dijalankan.",
            "raw_output": output_text
        }, ensure_ascii=False))
        sys.exit(0)


def main():
    try:
        payload = json.load(sys.stdin)
    except Exception:
        fail("Payload JSON tidak valid.")

    required_fields = [
        "username",
        "full_name",
        "password",
        "new_user_role",
        "new_user_division",
        "requester_role"
    ]

    for field in required_fields:
        if field not in payload:
            fail(f"Field '{field}' wajib ada.")

    ad_host = os.getenv("AD_HOST")
    ad_user = os.getenv("AD_USER")
    ad_pass = os.getenv("AD_PASS")
    ad_auth = os.getenv("AD_AUTH", "ntlm")
    ad_port = int(os.getenv("AD_PORT", "5985"))
    ad_ssl = os.getenv("AD_SSL", "false").lower() == "true"

    ps_script_path = os.getenv(
        "AD_PS_SCRIPT_PATH",
        r"C:\PBL-Scripts\Create-AdUserFromWeb.ps1"
    )

    # Credential ini dipakai di dalam remote PowerShell session untuk akses SMB/Samba.
    # Tanpa ini, WinRM sering kena masalah double-hop: user AD berhasil dibuat,
    # tetapi folder \\sambafs\SHARE\NamaMember gagal dibuat.
    samba_domain = os.getenv("SAMBA_DOMAIN", "PBL205")
    samba_user = os.getenv("SAMBA_USER", "")
    samba_password = os.getenv("SAMBA_PASSWORD", "")
    set_member_folder_acl = os.getenv("PBL_SET_MEMBER_FOLDER_ACL", "false")
    fail_on_acl_error = os.getenv("PBL_FAIL_ON_ACL_ERROR", "false")

    if not ad_host:
        fail("Environment AD_HOST belum diset.")

    if not ad_user:
        fail("Environment AD_USER belum diset.")

    if not ad_pass:
        fail("Environment AD_PASS belum diset.")

    payload_b64 = base64.b64encode(
        json.dumps(payload).encode("utf-8")
    ).decode("ascii")

    ps_script_path_safe = ps_script_path.replace("'", "''")

    def b64(value):
        return base64.b64encode(str(value).encode("utf-8")).decode("ascii")

    samba_domain_b64 = b64(samba_domain)
    samba_user_b64 = b64(samba_user)
    samba_password_b64 = b64(samba_password)
    set_member_folder_acl_b64 = b64(set_member_folder_acl)
    fail_on_acl_error_b64 = b64(fail_on_acl_error)

    ps_script = f"""
$ErrorActionPreference = "Stop"

$PayloadJson = [System.Text.Encoding]::UTF8.GetString(
    [System.Convert]::FromBase64String("{payload_b64}")
)

$Payload = $PayloadJson | ConvertFrom-Json
$ScriptPath = '{ps_script_path_safe}'

$Username = [string]$Payload.username
$FullName = [string]$Payload.full_name
$Password = [string]$Payload.password
$NewUserRole = [string]$Payload.new_user_role
$NewUserDivision = [string]$Payload.new_user_division
$RequesterRole = [string]$Payload.requester_role
$RequesterDivision = ""
$ProvisionMemberFolder = "false"
$MemberFolderName = ""
$DivisionShare = ""
$SambaDomain = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String("{samba_domain_b64}"))
$SambaUser = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String("{samba_user_b64}"))
$SambaPassword = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String("{samba_password_b64}"))
$SetMemberFolderAcl = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String("{set_member_folder_acl_b64}"))
$FailOnAclError = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String("{fail_on_acl_error_b64}"))

if ($null -ne $Payload.requester_division) {{
    $RequesterDivision = [string]$Payload.requester_division
}}
if ($null -ne $Payload.provision_member_folder -and [bool]$Payload.provision_member_folder) {{
    $ProvisionMemberFolder = "true"
}}
if ($null -ne $Payload.member_folder_name) {{
    $MemberFolderName = [string]$Payload.member_folder_name
}}
if ($null -ne $Payload.division_share) {{
    $DivisionShare = [string]$Payload.division_share
}}

$ArgsList = @(
    "-NoProfile",
    "-ExecutionPolicy", "Bypass",
    "-File", $ScriptPath,
    "-Username", $Username,
    "-FullName", $FullName,
    "-Password", $Password,
    "-NewUserRole", $NewUserRole,
    "-NewUserDivision", $NewUserDivision,
    "-RequesterRole", $RequesterRole
)

if (-not [string]::IsNullOrWhiteSpace($RequesterDivision)) {{
    $ArgsList += @("-RequesterDivision", $RequesterDivision)
}}

$ArgsList += @("-ProvisionMemberFolder", $ProvisionMemberFolder)

if (-not [string]::IsNullOrWhiteSpace($MemberFolderName)) {{
    $ArgsList += @("-MemberFolderName", $MemberFolderName)
}}
if (-not [string]::IsNullOrWhiteSpace($DivisionShare)) {{
    $ArgsList += @("-DivisionShare", $DivisionShare)
}}

if (-not [string]::IsNullOrWhiteSpace($SambaUser) -and -not [string]::IsNullOrWhiteSpace($SambaPassword)) {{
    $ArgsList += @("-SambaDomain", $SambaDomain)
    $ArgsList += @("-SambaUser", $SambaUser)
    $ArgsList += @("-SambaPassword", $SambaPassword)
}}

$ArgsList += @("-SetMemberFolderAcl", $SetMemberFolderAcl)
$ArgsList += @("-FailOnAclError", $FailOnAclError)

& powershell.exe @ArgsList
"""

    try:
        with Client(
            ad_host,
            username=ad_user,
            password=ad_pass,
            ssl=ad_ssl,
            port=ad_port,
            auth=ad_auth
        ) as client:
            output, streams, had_errors = client.execute_ps(ps_script)

        output_text = normalize_output(output)

        # Cek output JSON dari PS1 lebih dulu, baik success maupun gagal.
        # Sebelumnya hanya success=true yang diteruskan. Akibatnya saat PS1 sudah
        # mengembalikan JSON error yang jelas, pesan tersebut tertutup oleh
        # error stream generik seperti "Access is denied".
        if output_text:
            try:
                parsed = json.loads(output_text)
                print(json.dumps(parsed, ensure_ascii=False))
                sys.exit(0 if parsed.get("success") else 1)
            except Exception:
                pass

        # Kalau PS1 tidak menghasilkan JSON valid, baru pakai error stream WinRM.
        if had_errors:
            error_text = normalize_error_stream(streams)
            fail("PowerShell error: " + error_text, {
                "stdout": output_text
            })

        print_success_from_output(output_text)

    except Exception as e:
        fail(f"Gagal konek/jalankan WinRM: {str(e)}")


if __name__ == "__main__":
    main()