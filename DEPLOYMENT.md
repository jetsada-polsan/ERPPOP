# Deploy Workflow

This project is prepared for a mobile-friendly Codex workflow:

1. Codex edits files in this repository.
2. Codex commits the change.
3. Codex pushes to GitHub.
4. Codex deploys the same committed source to the production server over SSH.

## One-time setup

Create `deploy/env.local` from `deploy/env.example` and fill in `GIT_REMOTE_URL`.

Do not commit `deploy/env.local`; it may contain private repository or server details.

## Normal publish

```bash
./scripts/publish-mobile.sh "Describe the change"
```

The script will:

- commit any local changes
- push to `origin/main`
- rsync source code to `/var/www/jeterp`
- avoid overwriting `.env`, `vendor`, `node_modules`, `storage` runtime files, downloads, and backups
- run Laravel clear/cache maintenance commands and migrations

## Deploy only

```bash
./scripts/deploy-ssh.sh
```

Use this after the code is already committed and ready to deploy.

## Notes

- Keep GitHub private for this ERP project.
- Store production secrets only in the server `.env`, not in GitHub.
- Prefer SSH keys for unattended deploys. Password-based SSH can still work manually, but it is less reliable for automation.
