..  include:: /Includes.rst.txt

..  _mcp:

================
TYPO3 MCP server
================

The extension can expose TYPO3 itself as a Streamable HTTP MCP server.
The endpoint is intentionally small and policy-driven:

-   In local TYPO3 ``Development`` / ``Testing`` context, the default
    ``auto`` mode works without WorkOS so developers can connect an MCP
    client immediately.
-   In TYPO3 ``Production`` context, the same ``auto`` mode requires a
    WorkOS AuthKit bearer token unless you explicitly switch the MCP
    authentication mode to ``anonymous``.
-   WorkOS remains the source of truth for MCP applications. TYPO3 does
    not keep a second list of MCP servers; it asks WorkOS which Connect
    applications the current WorkOS user has authorized and exposes up
    to the configured limit, capped at ``10``.

This follows the WorkOS MCP model: AuthKit is the OAuth authorization
server, TYPO3 is the resource server, and the MCP client obtains bearer
tokens through the standard MCP OAuth flow.

..  seealso::

    -   `WorkOS AuthKit MCP documentation <https://workos.com/docs/authkit/mcp>`__
    -   `MCP Streamable HTTP transport <https://modelcontextprotocol.io/specification/2025-11-25/basic/transports>`__
    -   `MCP tools specification <https://modelcontextprotocol.io/specification/draft/server/tools>`__

Runtime endpoints
=================

The default endpoint is:

..  code-block:: text

    https://example.com/workos-auth/mcp

The endpoint accepts JSON-RPC MCP requests via ``POST``. It supports:

..  list-table::
    :header-rows: 1

    *   -   MCP method
        -   Purpose
    *   -   ``initialize``
        -   Negotiates protocol version and declares the TYPO3
            ``tools`` capability.
    *   -   ``tools/list``
        -   Lists the TYPO3 MCP tools.
    *   -   ``tools/call``
        -   Calls the TYPO3 MCP tools.
    *   -   ``ping``
        -   Health check.

The extension also exposes the OAuth discovery helper endpoints used by
MCP clients:

..  code-block:: text

    https://example.com/.well-known/oauth-protected-resource
    https://example.com/.well-known/oauth-authorization-server

The protected-resource metadata points clients at your configured
AuthKit domain. The authorization-server metadata endpoint proxies
AuthKit's own ``/.well-known/oauth-authorization-server`` document for
clients that still expect that compatibility path on the MCP server.

Tools exposed by TYPO3
======================

``workos.mcp_context``
    Returns the effective MCP authentication mode, the WorkOS user id,
    the e-mail claim if present, and the linked TYPO3 ``fe_users`` /
    ``be_users`` records including their group UIDs.

``workos.authorized_mcp_servers``
    Returns the WorkOS Connect applications authorized by the current
    WorkOS user. This is the convenience layer that avoids duplicating
    MCP server configuration inside TYPO3: add or revoke the MCP
    application in WorkOS, and TYPO3 reflects that WorkOS mapping on the
    next call.

TYPO3 user and group mapping
============================

Do not add a boolean "is WorkOS group" flag to ``fe_users`` or
``be_users``. Users and groups remain TYPO3 authorization records.
WorkOS identity linkage belongs in ``tx_workosauth_identity``.

The MCP request flow uses that table like this:

#.  A WorkOS-authenticated MCP request arrives with a bearer token.
#.  TYPO3 verifies the token against the configured AuthKit domain.
#.  TYPO3 reads the WorkOS user id from the token subject.
#.  TYPO3 looks for matching ``tx_workosauth_identity`` rows in both
    contexts:

    -   ``login_context = 'frontend'`` + ``user_table = 'fe_users'``
    -   ``login_context = 'backend'`` + ``user_table = 'be_users'``

#.  If matching TYPO3 users exist and are enabled, TYPO3 reads their
    ``usergroup`` fields and exposes those group UIDs to MCP tools.

That means existing frontend/backend provisioning rules still apply.
For automatic mapping, enable frontend/backend auto-creation or
link-by-email and let the user sign in once through the normal WorkOS
TYPO3 login flow. After that, MCP calls see the same local user and
group mapping.

Database schema
===============

The WorkOS identity mapping table is part of the TYPO3 extension schema
in :file:`ext_tables.sql`. It is created in the normal TYPO3 database,
through TYPO3's schema migrator, not in a separate database.

Use one of TYPO3's standard schema update paths after installing or
updating the extension:

..  code-block:: bash

    vendor/bin/typo3 extension:setup --extension=workos_auth

Alternatively, open :guilabel:`WorkOS` -> :guilabel:`MCP Server` and
use the :guilabel:`Database schema` card. The button there delegates to
TYPO3's schema migrator and applies only pending WorkOS table changes
from :file:`ext_tables.sql`.

Development mode without WorkOS
===============================

For local development, open :guilabel:`WorkOS` ->
:guilabel:`MCP Server` in the TYPO3 backend and keep:

..  code-block:: php

    'mcpEnabled' => '1',
    'mcpAuthenticationMode' => 'auto',

When ``TYPO3_CONTEXT`` is ``Development`` or ``Testing``, no WorkOS
token is required. This lets you connect a local MCP client before
AuthKit is configured.

Minimal initialize request:

..  code-block:: bash

    curl -s https://example.ddev.site/workos-auth/mcp \
      -H 'Content-Type: application/json' \
      -d '{
        "jsonrpc": "2.0",
        "id": 1,
        "method": "initialize",
        "params": {
          "protocolVersion": "2025-11-25",
          "capabilities": {},
          "clientInfo": {"name": "local-test", "version": "1.0.0"}
        }
      }'

List tools:

..  code-block:: bash

    curl -s https://example.ddev.site/workos-auth/mcp \
      -H 'Content-Type: application/json' \
      -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'

Production with WorkOS
======================

For production, open :guilabel:`WorkOS` -> :guilabel:`MCP Server`,
use ``auto`` or ``workos`` mode and configure ``mcpAuthkitDomain``:

..  code-block:: php

    'mcpEnabled' => '1',
    'mcpServerPath' => '/workos-auth/mcp',
    'mcpAuthenticationMode' => 'auto',
    'mcpAuthkitDomain' => 'https://your-project.authkit.app',
    'mcpWorkosDiscovery' => '1',
    'mcpServerLimit' => '10',
    'mcpVerboseLogging' => '0',

Then configure WorkOS:

#.  In the WorkOS Dashboard, enable AuthKit / Connect for the project.
#.  In Connect configuration, enable Client ID Metadata Document (CIMD).
    Enable Dynamic Client Registration (DCR) only if you need older MCP
    client compatibility.
#.  Add your TYPO3 MCP server as the protected resource:
    ``https://example.com/workos-auth/mcp``.
#.  Add or authorize the MCP applications in WorkOS. Those WorkOS
    mappings are the source of truth; no extra TYPO3 list is needed.
#.  Make sure the user signs in to TYPO3 through WorkOS at least once if
    TYPO3-specific frontend/backend user and group mapping should be
    available to MCP tools.
#.  Point the MCP client at
    ``https://example.com/workos-auth/mcp``.

When a client calls the TYPO3 MCP endpoint without a token in production,
TYPO3 responds with ``401`` and a ``WWW-Authenticate`` header containing
the protected-resource metadata URL. MCP clients that support OAuth
discovery use that URL to find AuthKit and complete the user flow.

Logging
-------

Verbose MCP logging is controlled by ``mcpVerboseLogging``. When it is
enabled, TYPO3 logs:

-   MCP method names, such as ``initialize`` or ``tools/call``
-   the WorkOS user id when present
-   WorkOS discovery failures

Bearer tokens, WorkOS API keys and JWT-shaped values are passed through
``SecretRedactor`` before logging. Keep verbose logging off in normal
production operation and enable it temporarily when you need to see what
an MCP client is calling.

What this does not do
=====================

TYPO3 does not store per-MCP-server OAuth tokens and does not proxy
arbitrary remote MCP tool calls. That is deliberate: the WorkOS mapping
is the authority, and avoiding duplicated local token state is the main
way to avoid "MCP hell". If a project needs TYPO3 to become a full MCP
gateway/proxy later, add a separate trust policy for remote transports,
tool names, confirmation prompts and per-group authorization before
forwarding calls.
