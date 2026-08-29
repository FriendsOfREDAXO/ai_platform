---
name: ai-platform-release
description: Release- und Publish-Workflow fuer das ai_platform Addon zu REDAXO.org (FriendsOfREDAXO/installer-action). Use whenever the user wants to cut a new release/tag, debug the publish-to-redaxo workflow, bumpen die composer-deps oder Probleme mit Symfony-AI-Versionspins auf PHP 8.2 hat.
---

# KI Platform Release Workflow

Lokale Notizen zum Release-Pfad dieses Addons. Repo lebt auf `https://github.com/FriendsOfREDAXO/ai_platform` (Branch `main`). Auf myredaxo.com unter Slug `ai_platform`.

## Pre-Release-Checkliste

1. `package.yml::version` zeigt auf die geplante Version (Format `X.Y.Z` oder `X.Y.Z-betaN`, kein `v`-Praefix).
2. `vendor/` und `composer.lock` sind **eingecheckt** (FoR-Konvention; siehe `.gitignore` — `vendor/` darf da NICHT auftauchen).
3. `composer.json::config.platform.php = "8.2.0"` ist gesetzt. Ohne diesen Pin zieht ein lokaler `composer update` auf PHP >= 8.4 die Symfony-8.x-Pakete (clock/uid/serializer/event-dispatcher/property-access/property-info/string/type-info), die im CI mit PHP 8.2 nicht installierbar sind und den `ramsey/composer-install`-Step crashen lassen.
4. Nach `composer update` lokal kontrollieren, dass alle `symfony/*`-Pakete in `composer.lock` auf der **7.4er Linie** liegen (oder neuer mit PHP-8.2-Support). Schnellcheck:
   ```bash
   php -r '$lock = json_decode(file_get_contents("composer.lock"), true); foreach ($lock["packages"] as $p) { if (preg_match("#^symfony/#", $p["name"])) echo str_pad($p["name"], 40) . " " . $p["version"] . "\n"; }'
   ```
5. README, CLAUDE.md und Code: kein `/Users/<name>/...` und keine echten Tokens drin (globale Regel).

## Release schneiden

```bash
gh release create 1.0.0-beta1 \
  --repo FriendsOfREDAXO/ai_platform \
  --target main \
  --title "1.0.0-beta1" \
  --prerelease \                    # NUR bei -beta / -rc / -alpha
  --notes "$(cat <<'EOF'
... Release Notes ...
EOF
)"
```

Triggert automatisch `.github/workflows/publish-to-redaxo.yml` ueber das `release: published` Event.

## Workflow-Monitoring

```bash
gh run list --repo FriendsOfREDAXO/ai_platform --limit 3
gh run watch <run-id> --repo FriendsOfREDAXO/ai_platform --exit-status
gh run view <run-id>  --repo FriendsOfREDAXO/ai_platform --log-failed | tail -40
```

## Stolpersteine und Fixes

### "Your lock file does not contain a compatible set of packages. Please run composer update."
**Ursache:** `composer.lock` enthaelt Symfony-8.x-Pakete (PHP >= 8.4), CI laeuft mit PHP 8.2.
**Fix:** `config.platform.php = "8.2.0"` in composer.json setzen, `rm -rf vendor composer.lock && composer install --no-dev`, beides committen.

### "Could not fetch addon ai_platform. Please check your addon key and your MyRedaxo credentials."
**Ursache (typisch):** Addon auf myredaxo.com nicht angelegt, nicht aktiv, oder der Org-Bot-Account (hinter `MYREDAXO_USERNAME`/`MYREDAXO_API_KEY` der FriendsOfREDAXO-Org-Secrets) ist nicht als Maintainer des Addons eingetragen.
**Fix:** Auf my.redaxo.org Slug `ai_platform` anlegen, Bot als Co-Maintainer, Status auf aktiv. Danach Workflow neu starten — **nicht das Release neu erstellen**, sondern:
```bash
gh run rerun <run-id> --repo FriendsOfREDAXO/ai_platform
```

### Release zeigt auf alten Commit
Wenn nach dem `gh release create` noch Commits auf `main` kamen, ist der Tag am alten Commit verankert.
```bash
gh release delete <tag> --repo FriendsOfREDAXO/ai_platform --cleanup-tag --yes
gh release create <tag> --repo FriendsOfREDAXO/ai_platform --target main ...
```

## Versionierungs-Konventionen

- Tag-Name = `package.yml::version` (kein `v`-Praefix).
- `-beta`/`-rc`/`-alpha`-Releases mit `--prerelease` flaggen.
- composer.json hat **kein** eigenes `version`-Feld (Composer warnt sonst sogar) — Version kommt aus dem Tag bzw. package.yml.

## Secrets

Werden auf **Organisationsebene** in FriendsOfREDAXO verwaltet, nicht im Repo:
- `MYREDAXO_USERNAME`
- `MYREDAXO_API_KEY`

Repo-Settings → Secrets sieht leer aus — das ist normal. Wenn der Workflow `Using login: ***` (mit Wert) loggt, sind sie da.

## gh CLI

Eingeloggt als `dergel` (`gh auth status`). Scopes umfassen `repo` und `workflow`, also reicht das fuer Releases + Reruns. Org-Secrets selbst auflisten geht nicht (403, kein Org-Admin).

## Workflow-Datei

`.github/workflows/publish-to-redaxo.yml` — Vorbild war `FriendsOfREDAXO/api`-Workflow. Action-Versionen sind aktualisiert (`checkout@v4`, `composer-install@v3`). Triggert nur auf `release: published`, keinen `workflow_dispatch`. Wenn man `gh workflow run` braucht, muesste man `workflow_dispatch` erst nachziehen — bisher nicht noetig, `gh run rerun` reicht.
