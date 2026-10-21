-- Local development: two shared tenant databases (shards) next to the central database.
CREATE DATABASE IF NOT EXISTS wurk_shard_1;
CREATE DATABASE IF NOT EXISTS wurk_shard_2;
GRANT ALL PRIVILEGES ON wurk_shard_1.* TO 'wurk'@'%';
GRANT ALL PRIVILEGES ON wurk_shard_2.* TO 'wurk'@'%';
FLUSH PRIVILEGES;
