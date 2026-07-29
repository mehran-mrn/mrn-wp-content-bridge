# Architecture

```text
Telegram/Bale getUpdates
        │
        ▼
 MessagePoller ──► messages (idempotent)
        │
        ▼
   durable jobs ──► ImportMessage ──► ArticleWorkflow
                                      │
                    ┌─────────────────┼─────────────────┐
                    ▼                 ▼                 ▼
              OpenAI Text       Media Import      OpenAI Images
                    │                 │                 │
                    └─────────────────┼─────────────────┘
                                      ▼
                         Draft / Pending / Publish
                                      │
                    ┌─────────────────┴─────────────────┐
                    ▼                                   ▼
            Approval callback                 Social fan-out
           (Telegram/Bale)             (Telegram/Bale/LinkedIn)
```

## Boundaries

- `src/Platform`: official external platform adapters
- `src/AI`: provider contracts and OpenAI implementations
- `src/Queue`: durable queue, router, worker and lease lock
- `src/Workflow`: application workflows; no admin rendering
- `src/Admin`: admin UI and post editor integration
- `src/Infrastructure`: schema, credentials, logging and repositories
- `src/CLI`: WP-CLI entrypoint

Platform and provider registries are filterable. Custom job types can be handled through `mrncb_handle_job_{type}` without editing the queue.

## Data model

Nine custom tables keep high-volume operational data separate from `wp_posts`:

- sources
- destinations
- messages
- jobs
- workflows
- social_posts
- approvers
- audit_logs
- logs

WordPress posts and attachments remain canonical site content. Credentials are encrypted before entering either options or custom tables.
