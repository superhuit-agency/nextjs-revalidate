# The log rotates at a size, and keeps one archive

Decided while grilling #178, for v2.1.0.

Nothing bounded the **log file**: `Logger::log()` only appended, and a
front-end that fails writes a line for every change it is sent. From 2.1.0,
a line about to be written to a log at or past a size limit first renames the
log to a single **log archive** beside it. The previous archive is destroyed by
that rename. The limit is 5 MB, and `nextjs_revalidate_log_max_size` can change
it. `0` or less turns rotation off. An integer written as a string of digits
counts as that integer, and anything else that isn't an integer falls back to
the default.

This is the first time the plugin destroys the log's evidence on purpose. ADR
0004 makes the log the only trace a failed revalidation leaves, and ADR 0024
says it is *moved, never deleted*. Both still hold everywhere except this one
rule. The alternative was a file that grows until the disk is full, which loses
the evidence *and* the site. Rotation is the one point where the plugin gives up
evidence, and it does so by a rule the operator can predict.

## Considered options

**Age instead of size.** Rejected: disk use under age limits is unpredictable,
because a failing front-end can write a month's normal volume in an hour.
Enforcing age also needs something that runs on days nothing is logged, and
wp-cron can't be relied on for that. A size limit can be checked cheaply at the
only moment the file changes.

**Trimming the oldest lines in place.** Rejected: it rewrites the file, which is
slow on a large one and loses any line another request appends during the
rewrite. A `rename()` is atomic and moves no data, which is the same reason ADR
0024's migration renames.

**Several archives, or a number the operator chooses.** Rejected: how far back
an operator can see is the product of size and count, so one number to tune is
enough, and the filter tunes size. Shifting `.1 → .2 → …` is also several
renames, each able to race with another writer rotating at the same moment.

**A setting instead of a filter.** Rejected: it would ask every operator a
question almost none of them can answer. The developers who need another number
look for a filter.

**A Clear log button.** Rejected in the same session. Deleting on a click that
can't be undone is a different decision from deleting by a predictable rule.

## Consequences

The **log viewer** reads into the archive when the live log is too short to
fill its view, so a rotation never looks like a gap in the history.

Uninstall leaves the archive behind with the log, as ADR 0024 already does for
the log itself.
