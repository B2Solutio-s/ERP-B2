# Descarga a esta laptop (Windows) el respaldo más reciente del ERP B2.
# La laptop se conecta al servidor (no al revés), así que funciona aunque estuviera apagada a la hora del respaldo.
#
# CONFIGURACIÓN (una sola vez, en PowerShell):
#   1) Crear una llave:           ssh-keygen -t ed25519
#   2) Copiarla al servidor:      type $env:USERPROFILE\.ssh\id_ed25519.pub | ssh b2admin@192.168.1.53 "cat >> ~/.ssh/authorized_keys"
#   3) Probar sin contraseña:     ssh b2admin@192.168.1.53 "echo ok"
#   4) Programarlo todos los días (se ejecuta al encender si la laptop estaba apagada):
#        $accion  = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$env:USERPROFILE\descargar-respaldos.ps1`""
#        $disparo = New-ScheduledTaskTrigger -Daily -At 9:00am
#        $ajustes = New-ScheduledTaskSettingsSet -StartWhenAvailable
#        Register-ScheduledTask -TaskName "Respaldo ERP B2" -Action $accion -Trigger $disparo -Settings $ajustes
#   (copia primero este archivo a  %USERPROFILE%\descargar-respaldos.ps1)

$Servidor   = "b2admin@192.168.1.53"
$Origen     = "/home/b2admin/backups"
$Destino    = Join-Path $env:USERPROFILE "Respaldos-ERP-B2"
$DiasGuardar = 30

New-Item -ItemType Directory -Force -Path $Destino | Out-Null

# Nombres del respaldo más reciente de la base de datos y de los documentos.
$remotos = ssh -o BatchMode=yes $Servidor "ls -1t $Origen/erp_b2_db_*.sql.gz | head -1; ls -1t $Origen/erp_b2_archivos_*.tar.gz | head -1"
if ($LASTEXITCODE -ne 0 -or -not $remotos) { Write-Error "No se pudo consultar el servidor."; exit 1 }

foreach ($ruta in $remotos) {
    $nombre = Split-Path $ruta -Leaf
    $local  = Join-Path $Destino $nombre
    if (-not (Test-Path $local)) {
        scp -o BatchMode=yes "${Servidor}:$ruta" $local
        if ($LASTEXITCODE -ne 0) { Write-Error "Falló la descarga de $nombre"; exit 1 }
        Write-Host "Descargado: $nombre"
    }
}

# Limpieza de respaldos locales antiguos.
Get-ChildItem $Destino -File | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$DiasGuardar) } | Remove-Item
