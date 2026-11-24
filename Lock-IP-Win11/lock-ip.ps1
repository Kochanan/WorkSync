# lock-ip.ps1 - give this Windows 11 PC a fixed (static) IPv4 address, or put it back to automatic (DHCP).
#
# Run as Administrator (or right-click lock-ip.cmd -> "Run as administrator"):
#
#   lock-ip.ps1                         lock the address the PC has NOW (same IP, gateway, DNS)
#   lock-ip.ps1 -IP 192.168.1.50        lock a chosen address (gateway/DNS/prefix taken from now)
#   lock-ip.ps1 -IP 192.168.1.50 -Prefix 24 -Gateway 192.168.1.1 -Dns 192.168.1.1,8.8.8.8
#   lock-ip.ps1 -Adapter "Ethernet"     pick the network adapter (when the PC has more than one)
#   lock-ip.ps1 -Dhcp                   undo: back to automatic IP and DNS
#   lock-ip.ps1 -List                   show adapters and their current IP only, change nothing
#
# Before changing anything it saves the old settings to C:\ProgramData\LockIP\backup-<adapter>-<time>.json.
#Requires -RunAsAdministrator
[CmdletBinding()]
param(
    [string]$Adapter,
    [string]$IP,
    [ValidateRange(8, 30)][int]$Prefix,
    [string]$Gateway,
    [string[]]$Dns,
    [switch]$Dhcp,
    [switch]$List
)
$ErrorActionPreference = 'Stop'

function Ok($m) { Write-Host "  OK   $m" -ForegroundColor Green }
function Warn($m) { Write-Host "  WARN $m" -ForegroundColor Yellow }
function Stop-Lock($m) { Write-Host ""; Write-Host "STOP: $m" -ForegroundColor Red; Write-Host "Nothing was changed."; exit 1 }

function Test-IPv4([string]$s) {
    $a = $null
    return [System.Net.IPAddress]::TryParse($s, [ref]$a) -and $a.AddressFamily -eq 'InterNetwork' -and $s -match '^\d+\.\d+\.\d+\.\d+$'
}
function To-UInt32([string]$s) { $b = ([System.Net.IPAddress]::Parse($s)).GetAddressBytes(); [Array]::Reverse($b); [BitConverter]::ToUInt32($b, 0) }
function Same-Subnet([string]$a, [string]$b, [int]$len) {
    $mask = if ($len -eq 0) { [uint32]0 } else { [uint32](([uint64]4294967295 -shl (32 - $len)) -band [uint64]4294967295) }
    return ((To-UInt32 $a) -band $mask) -eq ((To-UInt32 $b) -band $mask)
}

function Get-Current($ifIndex) {
    $addr = Get-NetIPAddress -InterfaceIndex $ifIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike '169.254.*' } | Select-Object -First 1
    $gw = Get-NetRoute -InterfaceIndex $ifIndex -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue |
        Sort-Object RouteMetric | Select-Object -First 1
    $dns = (Get-DnsClientServerAddress -InterfaceIndex $ifIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue).ServerAddresses
    $dhcpOn = (Get-NetIPInterface -InterfaceIndex $ifIndex -AddressFamily IPv4).Dhcp -eq 'Enabled'
    [pscustomobject]@{
        IP      = if ($addr) { $addr.IPAddress } else { $null }
        Prefix  = if ($addr) { [int]$addr.PrefixLength } else { $null }
        Gateway = if ($gw) { $gw.NextHop } else { $null }
        Dns     = @($dns | Where-Object { $_ })
        Dhcp    = $dhcpOn
    }
}

# ---------------------------------------------------------------------------
# Which adapter
$ups = @(Get-NetAdapter -Physical -ErrorAction SilentlyContinue | Where-Object Status -eq 'Up')
if ($List -or -not $ups) {
    Write-Host "Network adapters:"
    Get-NetAdapter -Physical | ForEach-Object {
        $c = Get-Current $_.ifIndex
        Write-Host ("  {0,-24} {1,-6} IP={2}/{3} gateway={4} DNS={5} mode={6}" -f $_.Name, $_.Status, $c.IP, $c.Prefix, $c.Gateway, ($c.Dns -join ','), $(if ($c.Dhcp) { 'automatic' } else { 'static' }))
    }
    if (-not $ups) { Stop-Lock 'no connected network adapter (plug in the LAN cable or connect Wi-Fi first)' }
    exit 0
}
if ($Adapter) {
    $nic = Get-NetAdapter -Name $Adapter -ErrorAction SilentlyContinue
    if (-not $nic) { Stop-Lock "adapter '$Adapter' not found. See the names with: lock-ip.ps1 -List" }
} else {
    $withGw = @($ups | Where-Object { (Get-Current $_.ifIndex).Gateway })
    if ($withGw.Count -eq 1) { $nic = $withGw[0] }
    elseif ($ups.Count -eq 1) { $nic = $ups[0] }
    else {
        Stop-Lock ("more than one connected adapter ({0}). Choose one: lock-ip.ps1 -Adapter `"Ethernet`"" -f (($ups | ForEach-Object Name) -join ', '))
    }
}
$idx = $nic.ifIndex
$cur = Get-Current $idx
Write-Host "Adapter: $($nic.Name) ($($nic.InterfaceDescription))"
Write-Host ("Now:     IP={0}/{1} gateway={2} DNS={3} mode={4}" -f $cur.IP, $cur.Prefix, $cur.Gateway, ($cur.Dns -join ','), $(if ($cur.Dhcp) { 'automatic (DHCP)' } else { 'static' }))
if ($nic.MediaType -match '802\.11' -or $nic.PhysicalMediaType -match '802\.11') {
    Warn 'this is Wi-Fi: on Windows a fixed IP applies to this adapter on EVERY Wi-Fi network it joins.'
}

# Save the old settings (one file per run, never overwritten) so they can be put back by hand if needed.
$bakDir = Join-Path $env:ProgramData 'LockIP'
New-Item -ItemType Directory -Force -Path $bakDir | Out-Null
$safe = ($nic.Name -replace '[^A-Za-z0-9_-]', '_')
$cur | Add-Member -NotePropertyName Adapter -NotePropertyValue $nic.Name -PassThru |
    Add-Member -NotePropertyName SavedAt -NotePropertyValue (Get-Date -Format s) -PassThru |
    ConvertTo-Json | Set-Content (Join-Path $bakDir ("backup-{0}-{1}.json" -f $safe, (Get-Date -Format yyyyMMdd-HHmmss))) -Encoding UTF8

# ---------------------------------------------------------------------------
# Undo: back to automatic
if ($Dhcp) {
    Get-NetRoute -InterfaceIndex $idx -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue | Remove-NetRoute -Confirm:$false
    Get-NetIPAddress -InterfaceIndex $idx -AddressFamily IPv4 -PrefixOrigin Manual -ErrorAction SilentlyContinue | Remove-NetIPAddress -Confirm:$false
    Set-NetIPInterface -InterfaceIndex $idx -AddressFamily IPv4 -Dhcp Enabled
    Set-DnsClientServerAddress -InterfaceIndex $idx -ResetServerAddresses
    ipconfig /renew "$($nic.Name)" | Out-Null
    Start-Sleep 3
    $now = Get-Current $idx
    Ok ("back to automatic: IP={0}/{1} gateway={2}" -f $now.IP, $now.Prefix, $now.Gateway)
    exit 0
}

# ---------------------------------------------------------------------------
# Work out the address to lock
$newIP = if ($IP) { $IP } else { $cur.IP }
$newPrefix = if ($PSBoundParameters.ContainsKey('Prefix')) { $Prefix } else { $cur.Prefix }
$newGw = if ($Gateway) { $Gateway } else { $cur.Gateway }
$newDns = if ($Dns) { @($Dns | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ }) } else { @($cur.Dns) }
if (-not $newDns) { $newDns = @($newGw) }

if (-not $newIP -or -not (Test-IPv4 $newIP)) { Stop-Lock "no valid IP to lock (give one with -IP)" }
if (-not $newPrefix) { Stop-Lock 'no subnet prefix known (give one with -Prefix 24)' }
if ($newIP -like '169.254.*') { Stop-Lock 'the PC has no real address yet (169.254.x.x): connect to the network first, or give -IP/-Gateway' }
if ($newGw) {
    if (-not (Test-IPv4 $newGw)) { Stop-Lock "gateway '$newGw' is not an IPv4 address" }
    if (-not (Same-Subnet $newIP $newGw $newPrefix)) { Stop-Lock "gateway $newGw is not in the same subnet as $newIP/$newPrefix" }
    if ($newGw -eq $newIP) { Stop-Lock 'IP and gateway cannot be the same' }
} else { Warn 'no gateway: this PC will reach only its own subnet (no Internet)' }
foreach ($d in $newDns) { if ($d -and -not (Test-IPv4 $d)) { Stop-Lock "DNS '$d' is not an IPv4 address" } }

if ($newIP -ne $cur.IP) {
    # Someone else answering on that address means a conflict: never take it.
    if (Test-Connection -ComputerName $newIP -Count 2 -Quiet -ErrorAction SilentlyContinue) {
        Stop-Lock "$newIP is already used by another device on the network. Choose another -IP"
    }
    $neigh = Get-NetNeighbor -IPAddress $newIP -ErrorAction SilentlyContinue | Where-Object { $_.State -in 'Reachable', 'Stale', 'Permanent' }
    if ($neigh) { Stop-Lock "$newIP is already used by device $($neigh[0].LinkLayerAddress). Choose another -IP" }
}
if ($cur.Dhcp -and $newIP -eq $cur.IP) {
    Warn "$newIP was handed out by the router (DHCP). The router may later give it to another device too."
    Warn 'Safer: reserve this IP for this PC in the router (DHCP reservation), or lock an IP outside the router''s DHCP range.'
}
Write-Host ("Lock:    IP={0}/{1} gateway={2} DNS={3}" -f $newIP, $newPrefix, $newGw, ($newDns -join ','))

# ---------------------------------------------------------------------------
# Apply
Set-NetIPInterface -InterfaceIndex $idx -AddressFamily IPv4 -Dhcp Disabled
Get-NetRoute -InterfaceIndex $idx -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue | Remove-NetRoute -Confirm:$false
Get-NetIPAddress -InterfaceIndex $idx -AddressFamily IPv4 -ErrorAction SilentlyContinue | Remove-NetIPAddress -Confirm:$false
$ipArgs = @{ InterfaceIndex = $idx; AddressFamily = 'IPv4'; IPAddress = $newIP; PrefixLength = $newPrefix }
if ($newGw) { $ipArgs.DefaultGateway = $newGw }
try {
    New-NetIPAddress @ipArgs | Out-Null
} catch {
    Write-Host "Setting the address failed: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host 'Putting the adapter back to automatic (DHCP)...'
    Set-NetIPInterface -InterfaceIndex $idx -AddressFamily IPv4 -Dhcp Enabled
    Set-DnsClientServerAddress -InterfaceIndex $idx -ResetServerAddresses
    exit 2
}
if ($newDns) { Set-DnsClientServerAddress -InterfaceIndex $idx -ServerAddresses $newDns }

Start-Sleep 3
$now = Get-Current $idx
Write-Host ""
Ok ("locked: IP={0}/{1} gateway={2} DNS={3}" -f $now.IP, $now.Prefix, $now.Gateway, ($now.Dns -join ','))
if ($newGw) {
    if (Test-Connection -ComputerName $newGw -Count 2 -Quiet -ErrorAction SilentlyContinue) { Ok "gateway $newGw answers" }
    else { Warn "gateway $newGw does not answer ping (some routers block ping; check that the Internet still works)" }
}
Write-Host "Old settings saved in $bakDir. To undo: lock-ip.ps1 -Dhcp"
