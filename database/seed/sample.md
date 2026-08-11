# Stead sample seed data
#
# Idempotently creates a development administrator and empty sample
# collections the first time `bin/serve --seed` runs.
#
# Administrator:
#   email:    admin@example.com
#   name:     Site Administrator
#   password: randomly generated on first run; printed by bin/serve --seed
#
# Sample collections:
#   slug: pages  - name: Pages
#   slug: posts  - name: Posts
#
# The seeder is idempotent: existing administrators and collections are
# preserved, only missing records are created. Re-running --seed produces no
# duplicates and exits successfully.