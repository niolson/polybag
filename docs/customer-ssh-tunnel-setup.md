# Customer Guide: Secure SSH Tunnel Setup for Database Import

This guide explains how to configure a secure SSH tunnel between PolyBag and your systems so PolyBag can import shipments from your database without exposing the database directly to the internet.

## What This Setup Does

When SSH tunneling is enabled, PolyBag connects to your SSH server first, then securely forwards traffic from that server to your database.

This setup uses two different SSH keys:

- **PolyBag SSH Public Key**: allows PolyBag to log in to your SSH server
- **SSH Server Host Key**: allows PolyBag to verify that it is connecting to the correct server

Both are required for a secure connection.

## What You Will Need

Before starting, make sure you have:

- The hostname or IP address of the SSH server PolyBag should connect to
- The SSH port, usually `22`
- The SSH username PolyBag should use
- The database host and port as seen from that SSH server
- Access to edit `~/.ssh/authorized_keys` for the SSH user
- Access to view the SSH server's host key

## Recommended Architecture

The recommended setup is:

- PolyBag connects to a dedicated SSH/bastion server
- That SSH server can reach your database over your internal network
- The database is **not** exposed publicly
- A dedicated SSH user is created for PolyBag

## Step 1: Create or Choose the SSH User

Create a dedicated user for PolyBag if possible, for example:

```bash
sudo adduser polybag
```

This user should:

- be able to log in via SSH key
- not require shell access beyond what is needed for tunneling
- be able to reach the database host and port from the SSH server

## Step 2: Copy the PolyBag SSH Public Key

In PolyBag, open:

`App Settings` -> `Database Import`

Enable:

`Connect via SSH Tunnel`

Copy the value from:

`SSH Public Key`

This is the public key that will allow PolyBag to authenticate to your SSH server.

## Step 3: Add the PolyBag Key to `authorized_keys`

On your SSH server, log in as the target SSH user or switch to that user.

Create the `.ssh` directory if needed:

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
```

Add the PolyBag public key to:

`~/.ssh/authorized_keys`

Then lock down permissions:

```bash
chmod 600 ~/.ssh/authorized_keys
```

### Recommended Restriction

If you know the database host and port that PolyBag should reach, restrict the key with `permitopen`.

Example:

```text
no-pty,no-X11-forwarding,no-agent-forwarding,permitopen="db.internal.example:3306" ssh-ed25519 AAAAC3Nza...
```

This limits the key so it can only open a tunnel to the approved database endpoint.

## Step 4: Get the SSH Server Host Key

PolyBag also needs your SSH server's **host key** so it can verify server identity.

This is different from the PolyBag public key above.

On the SSH server, run one of these commands:

```bash
cat /etc/ssh/ssh_host_ed25519_key.pub
```

or:

```bash
ssh-keyscan -t ed25519 your-ssh-host.example.com
```

If your SSH service is running on a non-standard port:

```bash
ssh-keyscan -p 2222 -t ed25519 your-ssh-host.example.com
```

The result should look like:

```text
your-ssh-host.example.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI...
```

Copy that full line.

## Step 5: Enter the SSH Settings in PolyBag

In PolyBag, complete these fields in:

`App Settings` -> `Database Import`

- `SSH Host`: the SSH server hostname or IP
- `SSH Port`: usually `22`
- `SSH User`: the PolyBag SSH username, for example `polybag`
- `Remote Host`: the database host as seen from the SSH server
- `Remote Port`: the database port as seen from the SSH server
- `SSH Server Host Key`: paste the host key line from Step 4

Also fill in the database connection fields:

- `Driver`
- `Host`
- `Port`
- `Database`
- `Username`
- `Password`

Notes:

- `Host` and `Port` are the database connection details PolyBag stores for import
- `Remote Host` and `Remote Port` are what the SSH server should connect to
- If your database host is the same from both perspectives, those values may match

## Step 6: Test the Connection

In the `Database Import` section, click:

`Test Connection`

If everything is configured correctly, PolyBag should report:

`Connection successful`

## Firewall Recommendations

For best security:

- allow inbound SSH only from the PolyBag server if possible
- do not expose the database publicly
- restrict the PolyBag SSH key with `permitopen`
- use a dedicated SSH account for PolyBag

## Troubleshooting

### `SSH key not found`

PolyBag does not have its local SSH keypair yet. Re-run provisioning or generate the application SSH key on the PolyBag server.

### `SSH server host key is required`

Paste the server host key into:

`SSH Server Host Key`

PolyBag will not trust a server automatically.

### `Host key verification failed`

The saved host key does not match the key presented by the SSH server.

Check:

- that `SSH Host` is correct
- that the SSH server was not rebuilt or rotated to a new host key
- that the copied host key matches the live server

If the server host key was intentionally rotated, update the `SSH Server Host Key` field in PolyBag.

### `Permission denied (publickey)`

Check:

- the PolyBag public key is present in `~/.ssh/authorized_keys`
- file permissions on `~/.ssh` and `authorized_keys` are correct
- the configured `SSH User` is correct

### Connection test reaches SSH but not the database

Check:

- `Remote Host` and `Remote Port`
- firewall rules between the SSH server and the database
- database bind address and access control

## Security Checklist

Before going live, confirm:

- the database is not publicly exposed
- a dedicated SSH user is being used
- the PolyBag public key is installed in `authorized_keys`
- the SSH key is restricted with `permitopen` if possible
- the SSH server host key is pasted into PolyBag
- the connection test succeeds

## Need Help

If you need help completing this setup, send your PolyBag contact:

- the SSH host
- the SSH port
- the SSH username
- whether the database is reachable from the SSH server
- the exact error shown by `Test Connection`
