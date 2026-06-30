Feature: Testing the update scenario of daily releases

  Scenario: Updating an outdated Nextcloud 35 daily
    Given There is a release with channel "daily"
    And The received version is "35.1.0"
    And the received build is "2012-10-19T18:44:30+00:00"
    When The request is sent
    Then The response is non-empty
    And Update to version "100.0.0.0" is available
    And URL to download is "https://download.nextcloud.com/server/daily/latest-master.zip"
    And URL to documentation is "https://docs.nextcloud.com/server/latest/admin_manual/maintenance/upgrade.html"
    And EOL date is set to ""
    And No signature is set

  Scenario: Updating an outdated Nextcloud 34 daily
    Given There is a release with channel "daily"
    And The received version is "34.1.0"
    And the received build is "2012-10-19T18:44:30+00:00"
    When The request is sent
    Then The response is non-empty
    And Update to version "100.0.0.0" is available
    And URL to download is "https://download.nextcloud.com/server/daily/latest-stable34.zip"
    And URL to documentation is "https://docs.nextcloud.com/server/34/admin_manual/maintenance/upgrade.html"
    And EOL date is set to ""
    And No signature is set

  Scenario: Updating an outdated Nextcloud 33 daily
    Given There is a release with channel "daily"
    And The received version is "33.1.0"
    And the received build is "2012-10-19T18:44:30+00:00"
    When The request is sent
    Then The response is non-empty
    And Update to version "100.0.0.0" is available
    And URL to download is "https://download.nextcloud.com/server/daily/latest-stable33.zip"
    And URL to documentation is "https://docs.nextcloud.com/server/33/admin_manual/maintenance/upgrade.html"
    And EOL date is set to "2027-02-18"
    And No signature is set

  Scenario: Updating an outdated Nextcloud 32 daily
    Given There is a release with channel "daily"
    And The received version is "32.1.0"
    And the received build is "2012-10-19T18:44:30+00:00"
    When The request is sent
    Then The response is non-empty
    And Update to version "100.0.0.0" is available
    And URL to download is "https://download.nextcloud.com/server/daily/latest-stable32.zip"
    And URL to documentation is "https://docs.nextcloud.com/server/32/admin_manual/maintenance/upgrade.html"
    And EOL date is set to "2026-09-27"
    And No signature is set
