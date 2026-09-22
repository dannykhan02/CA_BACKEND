# Document recovery

Confirm `railway status` shows project `superb-emotion`, environment `production`, before each production write. Use an explicitly authorized actor; deleted documents are excluded. The command shares the API's recovery workflow and billing checks.

```bash
railway status
railway ssh --service CA_BACKEND --environment production -- php artisan documents:reprocess <document-id> --actor=<authorized-user-id>
```

If the desktop SSH agent stalls, use direct OpenSSH with the current service instance ID and an unlocked identity. Do not share passphrases or tokens.

Exit zero means recovery was queued, not completed. Check the document's status, extracted-text length, processing_jobs, Horizon logs, and expected outputs. Use disposable records for verification; never rerun client documents merely to test the command.

Production Custom Start Commands must be `sh bin/start-production.sh web` for CA_BACKEND and `sh bin/start-production.sh worker` for ca-horizon-worker. Both cache Laravel configuration, enforce `config:check-production-safety`, attempt freshclam without hiding errors, and prove signatures load by scanning harmless data. Update failures are tolerated only with a successful scan using existing daily signatures. The final process uses `exec` for signal delivery.
