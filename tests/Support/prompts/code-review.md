---
name: code-review
title: Code review assistant
description: Reviews a diff with a given focus
arguments:
  - name: diff
    description: The diff to review
    required: true
  - focus
---
Review the following diff focusing on {{focus}}:

{{diff}}

Ignore {{undeclared}} placeholders.
