$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$projectRoot = Split-Path $PSScriptRoot -Parent
$assetsPath = Join-Path $projectRoot 'assets'
New-Item -ItemType Directory -Path $assetsPath -Force | Out-Null

$activityPath = 'M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2'
$iconSvg = @"
<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256" role="img" aria-label="Activity Log for Admins">
  <defs>
    <linearGradient id="background" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#1d3d73" />
      <stop offset="1" stop-color="#2456ad" />
    </linearGradient>
  </defs>
  <rect x="4" y="4" width="248" height="248" rx="54" fill="url(#background)" />
  <path d="$activityPath" transform="translate(62 62) scale(5.5)" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
</svg>
"@
[System.IO.File]::WriteAllText((Join-Path $assetsPath 'icon.svg'), $iconSvg.Trim(), [System.Text.UTF8Encoding]::new($false))

function New-RoundedPath {
    param(
        [single] $X,
        [single] $Y,
        [single] $Width,
        [single] $Height,
        [single] $Radius
    )
    $path = [System.Drawing.Drawing2D.GraphicsPath]::new()
    $diameter = [single]($Radius * 2)
    $path.AddArc($X, $Y, $diameter, $diameter, 180, 90)
    $path.AddArc(($X + $Width - $diameter), $Y, $diameter, $diameter, 270, 90)
    $path.AddArc(($X + $Width - $diameter), ($Y + $Height - $diameter), $diameter, $diameter, 0, 90)
    $path.AddArc($X, ($Y + $Height - $diameter), $diameter, $diameter, 90, 90)
    $path.CloseFigure()
    return $path
}

function Fill-RoundedRectangle {
    param(
        [System.Drawing.Graphics] $Graphics,
        [single] $X,
        [single] $Y,
        [single] $Width,
        [single] $Height,
        [single] $Radius,
        [System.Drawing.Brush] $Brush
    )
    $path = New-RoundedPath $X $Y $Width $Height $Radius
    $Graphics.FillPath($Brush, $path)
    $path.Dispose()
}

function Draw-ActivityGlyph {
    param(
        [System.Drawing.Graphics] $Graphics,
        [single] $X,
        [single] $Y,
        [single] $Size,
        [System.Drawing.Color] $Color
    )
    $scale = [single]($Size / 24)
    $sourcePoints = @(
        @(22, 12), @(19.52, 12), @(18.77, 12.14), @(18.10, 12.57), @(17.59, 13.46),
        @(15.24, 21.82), @(14.76, 21.82), @(9.24, 2.18), @(8.76, 2.18),
        @(6.41, 10.54), @(5.90, 11.43), @(5.23, 11.86), @(4.49, 12), @(2, 12)
    )
    $points = [System.Drawing.PointF[]]::new($sourcePoints.Count)
    for ($index = 0; $index -lt $sourcePoints.Count; $index++) {
        $points[$index] = [System.Drawing.PointF]::new(
            ($X + $sourcePoints[$index][0] * $scale),
            ($Y + $sourcePoints[$index][1] * $scale)
        )
    }
    $path = [System.Drawing.Drawing2D.GraphicsPath]::new()
    $path.AddLines($points)
    $pen = [System.Drawing.Pen]::new($Color, [single](2.2 * $scale))
    $pen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
    $pen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
    $pen.LineJoin = [System.Drawing.Drawing2D.LineJoin]::Round
    $Graphics.DrawPath($pen, $path)
    $pen.Dispose()
    $path.Dispose()
}

function New-IconPng {
    param([int] $Size, [string] $Path)
    $bitmap = [System.Drawing.Bitmap]::new($Size, $Size, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $graphics.Clear([System.Drawing.Color]::Transparent)
    $scale = [single]($Size / 256)
    $graphics.ScaleTransform($scale, $scale)
    $rect = [System.Drawing.Rectangle]::new(4, 4, 248, 248)
    $gradient = [System.Drawing.Drawing2D.LinearGradientBrush]::new(
        $rect,
        [System.Drawing.ColorTranslator]::FromHtml('#1d3d73'),
        [System.Drawing.ColorTranslator]::FromHtml('#2456ad'),
        [System.Drawing.Drawing2D.LinearGradientMode]::ForwardDiagonal
    )
    Fill-RoundedRectangle $graphics 4 4 248 248 54 $gradient
    Draw-ActivityGlyph $graphics 73 73 110 ([System.Drawing.Color]::White)
    $bitmap.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    $gradient.Dispose()
    $graphics.Dispose()
    $bitmap.Dispose()
}

function Draw-BannerText {
    param(
        [System.Drawing.Graphics] $Graphics,
        [string] $Text,
        [single] $X,
        [single] $Y,
        [single] $Size,
        [System.Drawing.FontStyle] $Style,
        [System.Drawing.Color] $Color
    )
    $font = [System.Drawing.Font]::new('Segoe UI', $Size, $Style, [System.Drawing.GraphicsUnit]::Pixel)
    $brush = [System.Drawing.SolidBrush]::new($Color)
    $Graphics.DrawString($Text, $font, $brush, [System.Drawing.PointF]::new($X, $Y))
    $brush.Dispose()
    $font.Dispose()
}

function Draw-ActivityRow {
    param(
        [System.Drawing.Graphics] $Graphics,
        [single] $Y,
        [string] $Title,
        [string] $Meta,
        [string] $Accent
    )
    $accentBrush = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml($Accent))
    $Graphics.FillEllipse($accentBrush, 449, ($Y + 3), 18, 18)
    $accentBrush.Dispose()
    Draw-BannerText $Graphics $Title 478 ($Y + 1) 9.6 ([System.Drawing.FontStyle]::Bold) ([System.Drawing.ColorTranslator]::FromHtml('#182538'))
    Draw-BannerText $Graphics $Meta 478 ($Y + 17) 7.7 ([System.Drawing.FontStyle]::Regular) ([System.Drawing.ColorTranslator]::FromHtml('#5b6c82'))
}

function New-BannerPng {
    param([int] $Scale, [string] $Path)
    $bitmap = [System.Drawing.Bitmap]::new((772 * $Scale), (250 * $Scale), [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
    $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $graphics.ScaleTransform([single]$Scale, [single]$Scale)

    $canvas = [System.Drawing.Rectangle]::new(0, 0, 772, 250)
    $background = [System.Drawing.Drawing2D.LinearGradientBrush]::new(
        $canvas,
        [System.Drawing.ColorTranslator]::FromHtml('#102341'),
        [System.Drawing.ColorTranslator]::FromHtml('#214d8f'),
        [System.Drawing.Drawing2D.LinearGradientMode]::Horizontal
    )
    $graphics.FillRectangle($background, $canvas)
    $background.Dispose()

    $halo = [System.Drawing.SolidBrush]::new([System.Drawing.Color]::FromArgb(22, 147, 181, 243))
    $graphics.FillEllipse($halo, 267, -117, 282, 282)
    $graphics.FillEllipse($halo, 698, 174, 152, 152)
    $halo.Dispose()
    $linePen = [System.Drawing.Pen]::new([System.Drawing.Color]::FromArgb(34, 203, 220, 247), 1)
    $graphics.DrawArc($linePen, 288, -165, 286, 286, 12, 110)
    $graphics.DrawArc($linePen, 313, -143, 238, 238, 13, 104)
    $linePen.Dispose()

    $logoGradient = [System.Drawing.Drawing2D.LinearGradientBrush]::new(
        [System.Drawing.Rectangle]::new(50, 68, 48, 48),
        [System.Drawing.ColorTranslator]::FromHtml('#2456ad'),
        [System.Drawing.ColorTranslator]::FromHtml('#4478cb'),
        [System.Drawing.Drawing2D.LinearGradientMode]::ForwardDiagonal
    )
    Fill-RoundedRectangle $graphics 50 68 48 48 13 $logoGradient
    $logoGradient.Dispose()
    Draw-ActivityGlyph $graphics 61 79 26 ([System.Drawing.Color]::White)

    Draw-BannerText $graphics 'WORDPRESS ACTIVITY HISTORY' 52 39 8.2 ([System.Drawing.FontStyle]::Bold) ([System.Drawing.ColorTranslator]::FromHtml('#b9cef1'))
    Draw-BannerText $graphics 'Activity Log' 112 67 28 ([System.Drawing.FontStyle]::Bold) ([System.Drawing.Color]::White)
    Draw-BannerText $graphics 'for WordPress Admins' 114 101 12.4 ([System.Drawing.FontStyle]::Regular) ([System.Drawing.ColorTranslator]::FromHtml('#d8e5f8'))
    Draw-BannerText $graphics 'See what changed, who changed it, and when.' 52 143 12.5 ([System.Drawing.FontStyle]::Regular) ([System.Drawing.ColorTranslator]::FromHtml('#f4f7fd'))
    Draw-BannerText $graphics 'SEARCH  ·  REVIEW  ·  EXPORT' 52 186 8.5 ([System.Drawing.FontStyle]::Bold) ([System.Drawing.ColorTranslator]::FromHtml('#b9cef1'))

    $shadow = [System.Drawing.SolidBrush]::new([System.Drawing.Color]::FromArgb(58, 4, 17, 39))
    Fill-RoundedRectangle $graphics 436 39 300 181 14 $shadow
    $shadow.Dispose()
    $cardBrush = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml('#ffffff'))
    Fill-RoundedRectangle $graphics 430 32 300 181 14 $cardBrush
    $cardBrush.Dispose()
    $cardLine = [System.Drawing.Pen]::new([System.Drawing.ColorTranslator]::FromHtml('#d9e2ee'), 1)
    $cardPath = New-RoundedPath 430 32 300 181 14
    $graphics.DrawPath($cardLine, $cardPath)
    $cardPath.Dispose()
    $cardLine.Dispose()
    Draw-BannerText $graphics 'Recent activity' 449 45 12 ([System.Drawing.FontStyle]::Bold) ([System.Drawing.ColorTranslator]::FromHtml('#182538'))
    Draw-BannerText $graphics 'A clear view of changes across your site' 449 62 8.2 ([System.Drawing.FontStyle]::Regular) ([System.Drawing.ColorTranslator]::FromHtml('#5b6c82'))

    $divider = [System.Drawing.Pen]::new([System.Drawing.ColorTranslator]::FromHtml('#e5ebf2'), 1)
    $graphics.DrawLine($divider, 449, 82, 711, 82)
    Draw-ActivityRow $graphics 88 'Updated a page' 'Page  ·  Content edited' '#dceaff'
    $graphics.DrawLine($divider, 449, 121, 711, 121)
    Draw-ActivityRow $graphics 127 'Changed an account role' 'Account  ·  Role changed' '#e4f1ed'
    $graphics.DrawLine($divider, 449, 160, 711, 160)
    Draw-ActivityRow $graphics 166 'Plugin updated' 'Plugin  ·  Version recorded' '#eeeafb'
    $divider.Dispose()

    $bitmap.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    $graphics.Dispose()
    $bitmap.Dispose()
}

New-IconPng 128 (Join-Path $assetsPath 'icon-128x128.png')
New-IconPng 256 (Join-Path $assetsPath 'icon-256x256.png')
New-BannerPng 1 (Join-Path $assetsPath 'banner-772x250.png')
New-BannerPng 2 (Join-Path $assetsPath 'banner-1544x500.png')

Get-ChildItem $assetsPath -File | Select-Object Name, Length
