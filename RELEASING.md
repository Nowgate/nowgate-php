# Releasing

1. Set the version in `composer.json`, and move `CHANGELOG.md`'s
   "Unreleased" section under it.
2. Push a tag `v<version>` on that commit. Packagist reads the tag: `nowgate/nowgate` gets the version
   within minutes, through the repository's webhook. Composer installs only `src/`,
   `composer.json`, `README.md`, `CHANGELOG.md` and `LICENSE` (see `.gitattributes`).
3. Check the registry: `composer show nowgate/nowgate --all`.
