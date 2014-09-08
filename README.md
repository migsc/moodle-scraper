moodle-scraper
==============

A quick and dirty PHP script to download important course files and pages from moodle.

Only made this way because my school's moodle site didn't support [web services](https://docs.moodle.org/dev/Web_services_Roadmap). That being said, if you know the moodle site does/can support it, I recommend finding some other implementation.


Installation and Usage
==============

Get composer if you don't have it already and run composer install in the project root.

Edit config.php to add your moodle classes. The IDs for your moodle classes should be at the end of the URL for the course home page. Example: https://moodle.cis.fiu.edu/v2.1/course/view.php?id=776

Run the following:
```
php update.php
```

You should see a print out of the classes and sections being traversed, as well as the file/page names being downloaded and their download paths.
