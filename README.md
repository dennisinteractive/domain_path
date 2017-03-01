Domain Path
======

This module has been ported to D8 by Dennis.

The issue to port is https://www.drupal.org/node/2821633 on https://www.drupal.org/project/domain_path

Current status
------
The a number of outstanding issues concerned with making it work with Pathauto module. The Pathauto module follows a different flow when generating aliases and makes it difficult to extend. Known issues include:
* We have removed the logic that allows alias storage service to respect the wishes of the passing service in regards to whether to update or insert a new record. This would be required to maintain all functionality in Pathauto module.
* Aliases don't respect access checks on nodes, meaning users with bypass node access permissions who can see a node can't always see the path alias.
* If an alias has been entered but the node has not been enabled on any domains, the alias will not be saved, and the path field will be cleared on next load.
