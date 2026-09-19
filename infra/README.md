# MySQL encryption-at-rest config

Three files, mounted into the standalone MySQL service by the root
[`docker-compose.yml`](../docker-compose.yml). They are here rather than inline in the
compose file because MySQL reads them as files, from three different paths inside the
container.

| File | Mounted at | Does |
| --- | --- | --- |
| `mysql.cnf` | `/etc/mysql/conf.d/encryption.cnf` | turns on table and binlog encryption |
| `mysqld.my` | `/usr/sbin/mysqld.my` | manifest that activates the keyring component |
| `component_keyring_file.cnf` | `/usr/lib64/mysql/plugin/component_keyring_file.cnf` | tells the component where to keep the key file |

**All three are required together.** `mysql.cnf` turns encryption on; the other two
supply the keys. Mounting `mysql.cnf` alone leaves MySQL 8.4 with encryption enabled and
no keyring to encrypt with, and it will not start.

Two failure modes here are silent rather than loud, which is why
[`scripts/install-onprem.sh`](../scripts/install-onprem.sh) asserts the end state after
install rather than trusting the config:

- A file that mysqld cannot read (uid 999) makes it discard the **entire** `conf.d`
  include directory and start with encryption off, looking perfectly healthy. A checkout
  made under a restrictive umask produces exactly that.
- A bind-mount source that does not exist makes Docker create a **directory** there, so
  the keyring config is a directory and never loads.

`mysql.cnf` carries a longer note on why the keyring **component** is used and why the
old `early-plugin-load` plugin form must not come back — it was removed in MySQL 8.4.

## Hosted deployment

Server operations tooling — the shared datastore stack, TLS front door, monitoring, and
the runbooks for all of it — is not in this repo. See the note in the
[README](../README.md#supported-deployment).
