# The log lives in a directory this plugin owns, under a name unique to the site

Decided while triaging #123, which was split out of #95.

`Logger::log()` appends to `nextjs-revalidate.log` in the site's uploads
directory, and nothing guards it. The filename is byte-identical on every
install of this plugin, so the path is not a secret from anybody; the file holds
every permalink revalidated, every outcome code, and the messages the front-end
and transport produced. #95 stops the shared secret reaching those messages, but
it cannot make the file safe to serve, and it does nothing at all for a log that
is already written.

From this release the log lives at `uploads/nextjs-revalidate/`, in a directory
carrying an `.htaccess` and an `index.php`, under a filename ending in a random
suffix generated once per site and stored in an option.

## The subdirectory is forced, not preferred

The obvious implementation — drop an `.htaccess` next to the log — **cannot be
done**, and the reason is worth writing down because it is not obvious until it
has broken a site. `.htaccess` applies to its own directory and everything
beneath it, so a `Deny from all` in `uploads/` would stop the web server
serving **every image and media file the site has**. The guard is only safe in a
directory nothing else writes to, so owning a directory is a precondition of
guarding anything.

## Two mechanisms, because one of them only works on Apache

`.htaccess` is read by Apache and ignored by nginx, and `index.php` only
suppresses a directory listing — neither stops `GET` for a known path on nginx.
A guard-file-only fix therefore reads as complete while protecting an unknown
fraction of installs, and the fraction is invisible from here.

So the filename also carries a **per-site random suffix**. It needs no server
configuration, which is exactly the property `.htaccess` lacks, and the threat it
addresses is real rather than theoretical: the current path is guessable because
it is the *same path on every install*, which is what makes it worth guessing.

This is obscurity, and it is being accepted deliberately rather than by
accident. It is not carrying the whole defence — on Apache the `.htaccess` is an
actual denial and the suffix is a second layer. For a diagnostic log this is the
right tier, and there is precedent in the ecosystem: WooCommerce names the files
under `wc-logs/` with a random hash for the same reason.

The operator loses nothing to it. The settings screen already prints the log's
full path beneath the logs switch, so the person who owns the site is told where
the file is; only somebody guessing from outside is inconvenienced.

## The suffix is stored, not derived

Deriving the suffix from `wp_salt()` and the site id would store nothing, need no
teardown and no migration, and be reproducible from the site's own state. It is
rejected because **salts get rotated** — routinely, and most often right after a
site is suspected of being compromised. A derived name changes at that moment:
the plugin begins writing to a new file, and the old one is left under its old
name, unreferenced, still holding every line ever written, still served. Salt
rotation would *manufacture* the orphaned-and-exposed file this ADR exists to
eliminate, at the worst possible time.

So the suffix is generated once with `wp_generate_password()` and kept in a
per-site option, on the pattern the DB version ledger already uses for internal
state that is not a setting.

## An existing log is moved, never deleted

Sites upgrading into this release already have a log at the old path, and it is
the whole reason this was split out of #95: redaction only protects lines not yet
written. A migration gated on the DB version ledger (ADR-0001) creates the
directory, writes the guards, and **renames** the old file into place.

It is guarded on the data — the old file exists and the new one does not —
rather than on the gate alone, which makes it idempotent by construction in the
way ADR-0017's split is.

Deleting the old log instead was considered and rejected. The log is the only
trace a failed revalidation ever leaves (ADR-0004), an operator may be reading it
at that moment, and a plugin update is not a thing anybody expects to destroy
their evidence.

## Consequences

`Logger::FILENAME` stops being the whole answer to "where is the log": the path
is now composed per site, and everything that reported it — the settings screen
help text, the manual test runbooks — composes it too rather than hardcoding it.

The new option must be deleted on uninstall. #72 is open against
`Settings::delete_settings()` for already missing one option; whichever of the
two lands second has to check the other.

On multisite each site gets its own directory, its own suffix and its own
migration, because `wp_upload_dir()` already follows `switch_to_blog()`. The
network-wide sweep that reaches unvisited subsites is ADR-0019's business and is
unchanged by this.

**The nginx half of this is unverified and will stay that way for now.** wp-env
runs Apache, so a check on a running site can prove the `.htaccess` denies and
can prove nothing about the case the suffix exists for. That is a known gap in
the evidence, not an oversight.
