# Releasing

1. **Document versions.** Any document whose wording changed has its `version` raised. Then `php tools/document-versions.php` records the new versions; `--check` must pass (the tests run it too).
2. **Spanish.** Run `dev/clinic_forms_translate_es.py` (openhealthcare workspace) for any changed patient document, or leave it out of date on purpose and say so in the changelog.
3. **Checks.** `vendor/bin/phpunit` on PHP 8.2 and 8.3; `php tools/footprint.php --check`; the end-to-end check (`dev/clinic_forms_check.py`) and the entry-order check on the 8.4.1 stack; `dev/module_compat_check.sh` on the official 7.0.3 and 8.5.0 images.
4. **Changelog.** List what changed, and the documents whose version moved:
   `git show v<previous>:data/document-versions.json > /tmp/before.json && php tools/document-versions.php --changes /tmp/before.json`
   A clinic with its own versions of those documents will see the page's "copied from an earlier version" notice.
5. **Tag** `v<version>`, wait for CI, publish the GitHub pre-release with the changelog section; Packagist follows the tag.
