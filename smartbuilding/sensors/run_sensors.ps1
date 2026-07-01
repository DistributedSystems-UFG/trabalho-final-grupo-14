param(
    [int]$NumSensors = 5,
    [int]$Interval = 3
)

Write-Host "Starting $NumSensors simulated sensors..." -ForegroundColor Green

$Rooms = @("101", "102", "201", "202", "301", "302", "401", "402", "501", "502", "601", "602", "701", "702")
$Jobs = @()

for ($i = 0; $i -lt $NumSensors; $i++) {
    $RoomIndex = $i % $Rooms.Count
    $RoomId = $Rooms[$RoomIndex]
    
    if ($i -ge $Rooms.Count) {
        $Floor = [Math]::Floor($i / 2) + 1
        $Suite = ($i % 2) + 1
        $RoomId = "${Floor}0${Suite}"
    }

    Write-Host "Launching sensor for Room $RoomId..."
    $job = Start-Process php -ArgumentList "sensor.php", "$RoomId", "$Interval" -WorkingDirectory $PSScriptRoot -NoNewWindow -PassThru
    $Jobs += $job
}

Write-Host "All $NumSensors sensors running. Press Ctrl+C (or close the terminal) to stop them." -ForegroundColor Yellow

# Wait for Ctrl+C or process exit
try {
    while ($true) {
        Start-Sleep -Seconds 1
    }
}
finally {
    Write-Host "`nStopping all sensors..." -ForegroundColor Red
    foreach ($job in $Jobs) {
        Stop-Process -Id $job.Id -Force -ErrorAction SilentlyContinue
    }
}
