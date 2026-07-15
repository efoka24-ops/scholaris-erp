$files = @(
  "apps\api\src\modules\timetables\timetables.controller.ts",
  "apps\api\src\modules\attendance\attendance.controller.ts",
  "apps\api\src\modules\discipline\discipline.controller.ts",
  "apps\api\src\modules\school-life\school-life.controller.ts",
  "apps\api\src\modules\library\library.controller.ts",
  "apps\api\src\modules\transport\transport.controller.ts",
  "apps\api\src\modules\catering\catering.controller.ts",
  "apps\api\src\modules\assets\assets.controller.ts",
  "apps\api\src\modules\hr\hr.controller.ts"
)

foreach ($file in $files) {
  $content = Get-Content $file -Raw
  $content = $content -replace '@Permissions\(', '@RequirePermissions('
  $content = $content -replace '@GetUser\(\) user: User', '@CurrentUser() user: AuthenticatedUser'
  Set-Content $file $content -NoNewline
  Write-Host "✓ Fixed $file"
}

Write-Host "`n✅ Tous les controllers ont été corrigés!"
