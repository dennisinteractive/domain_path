Domain Path
======

This module has been ported to D8 by Dennis.

The issue to port is https://www.drupal.org/node/2821633 on https://www.drupal.org/project/domain_path

Current status
------
The a number of outstanding issues concerned with making it work with Pathauto module. The Pathauto module follows a different flow when generating aliases and makes it difficult to extend. Known issues include:
* Pathauto generates aliases and saves them on hook_entity_insert, but the new Alias storage requires the entity to be saved (as per Core Path module), this means we cannot find the entity based on it's internal path which means we can't lookup which domain aliases to generate.
* Pathauto extends the Core Path FieldType and FieldWidget plugins for `path` directly, which means we have to extend this plugin conditionally depending on whether Pathauto is enabled.
* Aliases don't respect access checks on nodes, meaning users with bypass node access permissions who can see a node can't always see the path alias.