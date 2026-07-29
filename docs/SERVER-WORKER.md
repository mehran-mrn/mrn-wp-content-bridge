# Production worker

WP-Cron is available as a fallback, but production polling should be driven by the operating system.

## Cron

```cron
* * * * * cd /var/www/example/current && /usr/local/bin/wp mrn-content-bridge worker --quiet
```

The database lease prevents overlapping Cron invocations. Batch size and lease timeout are configured in the admin panel.

## Supervisor

```ini
[program:mrn-content-bridge]
command=/usr/local/bin/wp mrn-content-bridge worker --loop --sleep=5
directory=/var/www/example/current
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stdout_logfile=/var/log/mrn-content-bridge.log
stderr_logfile=/var/log/mrn-content-bridge-error.log
user=www-data
```

The command polls each active source, then reserves a bounded batch of durable jobs. Transient failures are retried with exponential backoff; terminal failures stay visible in the Jobs screen and can be retried manually.
