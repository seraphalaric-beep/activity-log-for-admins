# Agent instructions

## Testing and GitHub evidence policy
For each change, choose risk-proportionate unit, integration, smoke, sanity, regression, end-to-end, acceptance, security, accessibility, performance and compatibility checks as appropriate. Do not run every category by default or add unnecessary testing infrastructure. Test at relevant development, build, release and maintenance stages.
Record in the GitHub PR description (or for direct pushes, commit message or tracked test log) the tests actually performed, commands/CI references, pass/fail outcomes, significant omitted or blocked checks and remaining risks. Distinguish manual checks from automated tests. Never claim unrun tests passed. Preserve stricter project-specific requirements. Keep chat summaries brief.
