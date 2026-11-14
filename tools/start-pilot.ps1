param(
    [Parameter(Mandatory)] [string] $WordPress,
    [Parameter(Mandatory)] [string] $Php,
    [Parameter(Mandatory)] [int] $Port,
    [Parameter(Mandatory)] [string] $Database,
    [Parameter(Mandatory)] [string] $Password,
    [int] $DatabasePort = 33477
)

$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$site = (Resolve-Path -LiteralPath $WordPress).Path
$manifest = Get-Content -Raw -LiteralPath (Join-Path $project 'package.json') | ConvertFrom-Json
$archive = Join-Path $project ("release/activity-log-for-admins.{0}.zip" -f $manifest.version)
if (-not (Test-Path -LiteralPath $archive -PathType Leaf)) { throw "Build the installable archive first: $archive" }
if ($Database -notmatch '^alfa_[a-z0-9_]+$') { throw 'Use a disposable alfa_ database.' }
$config = Join-Path $site 'wp-config.php'
if (Test-Path $config) { throw 'Use a clean copy of WordPress with no existing wp-config.php.' }
$url = "http://127.0.0.1:$Port"
$keys = 'AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'
$salts = $keys | ForEach-Object { "define('$_', '$([Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)))');" }
$contents = @(
    '<?php'
    "define('DB_NAME', '$Database');"
    "define('DB_USER', 'root');"
    "define('DB_PASSWORD', '');"
    "define('DB_HOST', '127.0.0.1:$DatabasePort');"
    "define('DB_CHARSET', 'utf8mb4');"
    "define('DB_COLLATE', '');"
    "define('WP_DEBUG', true);"
    "define('WP_DEBUG_DISPLAY', false);"
    "define('WP_DEBUG_LOG', true);"
    "define('WP_HOME', '$url');"
    "define('WP_SITEURL', '$url');"
    "define('WP_HTTP_BLOCK_EXTERNAL', true);"
    "define('DISABLE_WP_CRON', true);"
    "define('FS_METHOD', 'direct');"
    $salts
    '$table_prefix = "wp_";'
    'require_once __DIR__ . "/wp-settings.php";'
)
[IO.File]::WriteAllLines($config, [string[]] $contents, [Text.UTF8Encoding]::new($false))
$phpDirectory = Split-Path (Resolve-Path -LiteralPath $Php).Path -Parent
$options = @('-n', '-d', "extension_dir=$phpDirectory/ext", '-d', 'extension=mysqli', '-d', 'extension=mbstring', '-d', 'extension=zip', '-d', 'extension=openssl', '-d', 'extension=curl', '-d', 'extension=fileinfo')
& $Php @options (Join-Path $PSScriptRoot 'site.php') $site $archive $url $Password
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'alfa-fixture.php') -Destination $site
& $Php @options -S "127.0.0.1:$Port" -t $site
