# Authentication

Every request carries a bearer token: a personal token you create in Settings, or an OAuth access token an app gets when you approve it.

{{TOC}}

## Bearer tokens

Send the token in the `Authorization` header of every request:

```text
Authorization: Bearer wm_api_your_token_here
```

The API reads nothing else. It does not use cookies or your signed-in session,
so being logged in to Whisper Money in the same browser is not enough: a request
without a token answers `401`.

There are two kinds of token. Both reach the same endpoints.

|             | Personal token                       | OAuth token                         |
| ----------- | ------------------------------------ | ----------------------------------- |
| For         | Your own scripts                     | Apps made by someone else           |
| Created in  | **Settings → API**                   | The app, after you approve it       |
| Permissions | Read only, or read & write           | Read & write                        |
| Lifetime    | 30 days, 90 days, 1 year or never    | Renewed by the app                  |
| Revoked in  | **Settings → API → Personal tokens** | **Settings → API → Connected apps** |

## Personal tokens

Create one in **Settings → API → Personal tokens**. Give it a name you will
recognise later, then pick:

- **Permission.** _Read only_ tokens can read your data. _Read & write_ tokens
  can also create, edit and delete it.
- **Expiration.** 30 days, 90 days (the default), 1 year or no expiration. An
  expired token answers `401`; create a new one, or rotate it before it expires.

The token is shown once, when you create it. It starts with `wm_api_`, which
makes it easy to spot if it ever ends up somewhere it should not, such as a
public repository. Store it like a password.

From the same list you can see when each token was last used and when it
expires, **rotate** it (a new value with the same name and permission, the old
one stops working at once) or **revoke** it.

### Scopes

Each endpoint in the [reference](/documentation/api/reference) says which
scope it needs:

- **read**: any `GET`. Read-only and read & write tokens both work.
- **write**: anything that creates, edits or deletes. A read-only token answers
  `403` with the code `insufficient_scope`.

## OAuth apps

An app made by someone else should not ask you to paste a token. It connects
with OAuth 2.1 instead, and you approve it on a Whisper Money screen:

1. The app registers itself through dynamic client registration:
   `POST https://whisper.money/oauth/register` with its name and redirect URIs.
   There is no developer portal and no client secret.
2. It sends you to `https://whisper.money/oauth/authorize` with the scope
   `api:use` and a PKCE challenge (`S256`).
3. You sign in and see the consent screen: the app's name, the address it will
   send you back to, and a warning that Whisper Money has not verified it. The
   app gets **read and write** access to your data.
4. The app exchanges the code at `https://whisper.money/oauth/token` and calls
   the API with the access token it receives, refreshing it with the refresh
   token when it expires.

The discovery documents list these addresses for clients that read them:
`/.well-known/oauth-authorization-server` and
`/.well-known/oauth-protected-resource/api/v1`.

Approve only apps you trust: anyone can register an app with any name. You can
see every app you approved, and when it last used the API, in **Settings → API →
Connected apps**. Revoking one cuts it off at once and invalidates all of its
tokens.

The plan that counts is the one of the person who approves the app, and the
shared demo accounts cannot approve apps.

## The API and the AI Connector are separate

The [AI Connector](/documentation/ai-connector) (MCP) has its own tokens and its
own connected apps, in **Settings → AI Connector**. They never work on the API,
and API tokens never work on the AI Connector: an AI Connector token sent to
`/api/v1` answers `403`. An OAuth app is approved for one of the two, never both.
