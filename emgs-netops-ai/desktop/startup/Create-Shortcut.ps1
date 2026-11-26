# Creates a desktop shortcut that starts EMGS NetOps AI. Run from the project folder:
#   powershell -ExecutionPolicy Bypass -File desktop\startup\Create-Shortcut.ps1
$root = (Resolve-Path "$PSScriptRoot\..\..").Path
$shell = New-Object -ComObject WScript.Shell
$lnk = $shell.CreateShortcut("$([Environment]::GetFolderPath('Desktop'))\EMGS NetOps AI.lnk")
$lnk.TargetPath = "$root\start_netops.bat"
$lnk.WorkingDirectory = $root
$lnk.Description = "Start EMGS NetOps AI (http://127.0.0.1:8750)"
$lnk.Save()
Write-Host "Shortcut created on the desktop."
