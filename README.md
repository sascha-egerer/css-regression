css-regression
==============
CSS Regression tests in Codeception

Install
-------
```console
$ composer require --dev saschaegerer/css-regression:dev-master
```

Configure
---------
```yaml
modules:
    enabled:
        - WebDriver:
            ...
        - \SaschaEgerer\CodeceptionCssRegression\Module\CssRegression:
            depends: 'WebDriver'
            referenceImageDirectory: 'referenceImages'
            failImageDirectory: 'failImages'
            maxDifference: 0.1
            automaticCleanup: true
```

Usage
-----
```php
$I->amOnPage('/');
$I->hideElements('.socialMediaButton');
$I->seeNoDifferenceToReferenceImage('NewsArticle', '#news-article');
```

