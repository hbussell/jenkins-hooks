jekins-hooks
============

Restful interface to jenkins builds and jobs
--------------------------------------------

This project aims to help integrate Jenkins build status for your feature branches into other systems.

It does this by exposing restful web actions to create Jenkins jobs and builds for branches and firing postbacks as builds are complete.


### Actions

`/job`

This action will create a job using the project template if one does not already exist

`/build-create`

Return the current build details for a branch or create a build if needed.

`/build-job-create`

Same as `/build-create` this action will return the current build details but it will also create the job if needed.


### Installation

Using Ansible


    sudo apt-get install ansible
    git clone git@github.com:hbussell/jenkins-hooks.git
    cd jenkins-hooks
    sudo ansible-playbook setup.yml -i inventory
