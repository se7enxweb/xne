<?php /* #?ini charset="utf-8"?

# Exponential Network (xne): the per-install settings. Override them in settings/override/xne.ini.append.php
# (xne reads that file; it never writes it). Keys and meanings: the xne package's etc/xne.conf.example.

[NetworkSettings]
# stable | beta | main | security
Channel=stable
# auto | filepatch | git | composer
Method=auto
# keep (keep serving during an upgrade) | page (503 maintenance page, admins can sign in)
Maintenance=keep
MaintenanceUntil=30m
BackupKeep=5
# ezcache | velocity | none
Finish=ezcache
# auto | kernel | sql | none
DatabaseUpgrade=auto
# Pages that must answer 200-399 after an upgrade
HealthURL[]
*/ ?>
