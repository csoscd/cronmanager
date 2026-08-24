# Single Sign-On (OIDC / OAuth 2.0)

Cronmanager supports SSO via any OpenID Connect 1.0 / OAuth 2.0 provider.
The following guide uses **Authentik** as the identity provider, but the steps
apply to any OIDC-compliant provider (Keycloak, Dex, Google Workspace, etc.).

---

## Table of Contents

1. [Basic Setup with Authentik](#basic-setup-with-authentik)
2. [Private CA Certificates (Homelab)](#private-ca-certificates-homelab)
3. [Provisioning Modes](#provisioning-modes)
4. [Group-Based Role Mapping (Authentik)](#group-based-role-mapping-authentik)
5. [Configuration Reference](#configuration-reference)
6. [Troubleshooting SSO](#troubleshooting-sso)

---

## Basic Setup with Authentik

### Step 1 – Create a provider in Authentik

1. Go to **Applications → Providers → Create**
2. Choose **OAuth2/OpenID Connect Provider**
3. Configure the provider:
   - **Name:** `Cronmanager`
   - **Client type:** Confidential
   - **Redirect URIs:** `https://cronmanager.example.com/auth/callback`
     (replace with your actual domain — must match `oidc_redirect_uri` exactly)
   - **Scopes:** `openid`, `email`, `profile`
4. After saving, note the **Client ID** and **Client Secret**

### Step 2 – Create an Application in Authentik

1. Go to **Applications → Applications → Create**
2. Set a name and slug (e.g. `cronmanager`)
3. Assign the provider created above
4. Save

### Step 3 – Find the Provider URL

Open the provider detail page and look for the
**OpenID Configuration URL** — it resembles:
```
https://auth.example.com/application/o/cronmanager/.well-known/openid-configuration
```

The value you need for `oidc_provider_url` is everything **before** `.well-known`:
```
https://auth.example.com/application/o/cronmanager/
```

### Step 4 – Configure Cronmanager

Edit `/opt/cronmanager/www/conf/config.json` (or set the corresponding env vars):

```json
{
    "auth": {
        "oidc_enabled":       true,
        "oidc_provider_url":  "https://auth.example.com/application/o/cronmanager/",
        "oidc_client_id":     "<client-id-from-authentik>",
        "oidc_client_secret": "<client-secret-from-authentik>",
        "oidc_redirect_uri":  "https://cronmanager.example.com/auth/callback",
        "oidc_ssl_verify":    true,
        "oidc_ssl_ca_bundle": ""
    }
}
```

Restart the web container to apply:

```bash
docker restart cronmanager-web
```

The login page now shows a **"Login with SSO"** button alongside the local login form.

---

## Private CA Certificates (Homelab)

If your Authentik instance uses a certificate issued by an internal CA:

```bash
# Copy the CA certificate (PEM format) to the config directory
cp root_ca.crt /opt/cronmanager/www/conf/root_ca.crt
chmod 644 /opt/cronmanager/www/conf/root_ca.crt
```

Then set in `config.json`:

```json
"oidc_ssl_ca_bundle": "/var/www/conf/root_ca.crt"
```

The `conf/` directory is mounted as `/var/www/conf` inside the container.

To disable certificate verification entirely (**not recommended**):
```json
"oidc_ssl_verify": false
```

---

## Provisioning Modes

The provisioning mode controls what happens when an SSO user logs in.
Set via `OIDC_AUTO_PROVISION` (or `auth.oidc_auto_provision` in `config.json`):

| Mode | Behaviour |
|---|---|
| `auto` (default) | A new local user record is created on first login with the `viewer` role. Admins can promote the user via the User Management page. |
| `disabled` | Only users whose account was pre-created in Cronmanager can log in via SSO. Unknown identities are rejected. Useful for tightly controlled environments. |
| `group` | The role is derived from the user's group membership in the OIDC token. Configure `OIDC_GROUP_*` env vars to define the mapping. See [Group-Based Role Mapping](#group-based-role-mapping-authentik) below. |

> Deleting an SSO user's Cronmanager account does **not** revoke their access at the
> OIDC provider. In `auto` and `group` mode the account is re-created on the next login.
> Use `disabled` mode to permanently block an SSO user after removing them locally.

---

## Group-Based Role Mapping (Authentik)

Group mapping lets Authentik assign Cronmanager roles automatically based on group
membership. When a user's group changes in Authentik, their Cronmanager role is updated
on the next login.

### Step 1 – Create groups in Authentik

1. Go to **Directory → Groups → Create**
2. Create the groups you need (names are arbitrary — you'll reference them in Cronmanager):

   | Group name | Maps to Cronmanager role |
   |---|---|
   | `cronmanager-admins` | `admin` |
   | `cronmanager-operators` | `operator` |
   | `cronmanager-viewers` | `viewer` |

### Step 2 – Assign users to groups

1. Open a group (e.g. `cronmanager-admins`) → **Users** tab
2. Click **Add existing user** and select the users that should have this role

### Step 3 – Create a "groups" scope mapping in Authentik

By default the Authentik OIDC token does **not** include a `groups` claim.
You must add a custom scope mapping:

1. Go to **Customization → Property Mappings → Create**
2. Select **Scope Mapping**
3. Fill in:
   - **Name:** `Cronmanager – groups`
   - **Scope name:** `groups`
   - **Description:** Exposes group names in the `groups` claim
   - **Expression:**
     ```python
     return [group.name for group in request.user.ak_groups.all()]
     ```
4. Save

### Step 4 – Attach the scope mapping to the Cronmanager provider

1. Open the Cronmanager provider (**Applications → Providers → Cronmanager**)
2. Scroll to **Advanced protocol settings → Scopes**
3. Add the `Cronmanager – groups` mapping you just created
4. Save the provider

> **Verify**: after a test login, decode the issued JWT at [jwt.io](https://jwt.io)
> and confirm the token contains `"groups": ["cronmanager-admins", ...]`.

### Step 5 – Configure Cronmanager

**Via environment variables** (`.env` / `docker-compose-full.yml`):

```dotenv
OIDC_AUTO_PROVISION=group
OIDC_GROUP_CLAIM=groups
OIDC_GROUP_ADMIN=cronmanager-admins
OIDC_GROUP_OPERATOR=cronmanager-operators
OIDC_GROUP_VIEWER=cronmanager-viewers
OIDC_DEFAULT_ROLE=viewer
```

**Via `config.json`:**

```json
{
    "auth": {
        "oidc_auto_provision":  "group",
        "oidc_group_claim":     "groups",
        "oidc_group_admin":     "cronmanager-admins",
        "oidc_group_operator":  "cronmanager-operators",
        "oidc_group_viewer":    "cronmanager-viewers",
        "oidc_default_role":    "viewer"
    }
}
```

Restart the web container to apply:

```bash
docker restart cronmanager-web
```

### How role resolution works at login

1. Authentik returns the `groups` claim in the OIDC token
2. Cronmanager checks the claim against `OIDC_GROUP_ADMIN`, then `OIDC_GROUP_OPERATOR`,
   then `OIDC_GROUP_VIEWER` — in that order (highest privilege first)
3. The first matching group wins; the user receives that role
4. If no group matches: `OIDC_DEFAULT_ROLE` is used
   — if `OIDC_DEFAULT_ROLE` is empty, login is **denied**
5. On every subsequent login the role is re-evaluated, so adding or removing a user
   from a group takes effect at their next login

---

## Configuration Reference

### Environment variables (web container)

| Variable | Default | Description |
|---|---|---|
| `OIDC_ENABLED` | `false` | Enable OIDC login |
| `OIDC_PROVIDER_URL` | _(empty)_ | OIDC provider base URL (with trailing slash) |
| `OIDC_CLIENT_ID` | _(empty)_ | OAuth 2.0 client ID |
| `OIDC_CLIENT_SECRET` | _(empty)_ | OAuth 2.0 client secret |
| `OIDC_REDIRECT_URI` | _(empty)_ | Callback URL (`https://your-domain/auth/callback`) |
| `OIDC_SSL_VERIFY` | `true` | Verify provider TLS certificate (`true` = system CA, `false` = disable) |
| `OIDC_SSL_CA_BUNDLE` | _(empty)_ | Path to custom CA bundle inside the container |
| `OIDC_AUTO_PROVISION` | `auto` | Provisioning mode: `auto`, `disabled`, or `group` |
| `OIDC_GROUP_CLAIM` | `groups` | OIDC claim containing the user's group list |
| `OIDC_GROUP_ADMIN` | _(empty)_ | Group name → `admin` role |
| `OIDC_GROUP_OPERATOR` | _(empty)_ | Group name → `operator` role |
| `OIDC_GROUP_VIEWER` | _(empty)_ | Group name → `viewer` role |
| `OIDC_DEFAULT_ROLE` | _(empty)_ | Fallback role when no group matches; empty = deny login |

### config.json keys

| Key | Default | Description |
|---|---|---|
| `auth.oidc_enabled` | `false` | Enable OIDC SSO |
| `auth.oidc_provider_url` | | Provider base URL (with trailing slash) |
| `auth.oidc_client_id` | | Client ID |
| `auth.oidc_client_secret` | | Client Secret |
| `auth.oidc_redirect_uri` | | Callback URL |
| `auth.oidc_ssl_verify` | `true` | `true` = system CA, `false` = disable, or path string |
| `auth.oidc_ssl_ca_bundle` | `""` | Path to custom PEM CA bundle (empty = system CA) |
| `auth.oidc_auto_provision` | `auto` | Provisioning mode |
| `auth.oidc_group_claim` | `groups` | Group claim name |
| `auth.oidc_group_admin` | `""` | Group → `admin` |
| `auth.oidc_group_operator` | `""` | Group → `operator` |
| `auth.oidc_group_viewer` | `""` | Group → `viewer` |
| `auth.oidc_default_role` | `""` | Fallback role; empty = deny |

---

## Troubleshooting SSO

| Symptom | Cause | Fix |
|---|---|---|
| `cURL error 60` on login | Provider certificate not trusted | Set `oidc_ssl_ca_bundle` to your CA cert path inside the container |
| `cURL error 77` | CA cert file not readable | `chmod 644 /opt/cronmanager/www/conf/root_ca.crt` |
| Role always `viewer` despite group membership | `groups` claim missing from token | Verify Steps 3 and 4 of group mapping; decode JWT to confirm |
| "Login failed" with `group` mode | No group matches and `OIDC_DEFAULT_ROLE` is empty | Set `OIDC_DEFAULT_ROLE=viewer` or add user to a mapped group |
| SSO user can log in despite being deleted | `auto`/`group` mode re-creates the account | Switch to `disabled` mode or deactivate the account in Cronmanager |
| Callback URL mismatch | Redirect URI does not exactly match registration | `OIDC_REDIRECT_URI` must match the URI registered in Authentik character-for-character |

Check the web log for detailed error messages:

```bash
# Docker mode
docker logs cronmanager-web

# or via log file
tail -f /opt/cronmanager/www/log/cronmanager-web.log
```
