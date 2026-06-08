Feature: Testing the update scenario of stable releases

  Scenario: Updating Nextcloud 32 on the stable channel
    Given There is a release with channel "stable"
    And The received version is "32.0.0.0"
    And The received PHP version is "8.2.0"
    And the installation mtime is "11"
    When The request is sent
    Then The response is non-empty
    And Update to version "32.0.11.1" is available
    And URL to download is "https://download.nextcloud.com/server/releases/nextcloud-32.0.11.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-32.0.11.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-32.0.11.tar.bz2"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v32.0.11/nextcloud-32.0.11.zip"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v32.0.11/nextcloud-32.0.11.tar.bz2"
    And URL to documentation is "https://docs.nextcloud.com/server/32/admin_manual/maintenance/upgrade.html"
    And EOL date is "2026-09-27"
    And The signature is
    """
    fjrCxIdnySTTWKZifdp5+2SUF31Vv7d2JR0i8LL6O7NZ76xmKk8mQY44ecwNErUs
    UbbEFO/s0WUbFGgU4ZUvDaxjYlQYYXkUAD6PSl/UnN/RBIQJhi0haM5oOsa+90oe
    b65FFNyX4uGHjAWMNhv9ChZc5rmZgv35lmMLMJ2O4W+1xhLabppucMWEvwd2+EV2
    V/Iy9Qo5gZx82vCbZigpn6OQo011DiRY1Q4i2hlirZsp8Jk18CjAznZQ0s+1RxRf
    EWl89AtaAYE7NPBhMYb5hTsDzRVizVUU3YYTCWqEpd4DV8q4RywFN03K9oiJAAdZ
    yvE6RPkGve7ePndOyln6mw==
    """

  Scenario: Updating Nextcloud latest 32 to 33 on the stable channel
    Given There is a release with channel "stable"
    And The received version is "32.0.11.1"
    And The received PHP version is "8.2.0"
    And the installation mtime is "11"
    When The request is sent
    Then The response is non-empty
    And Update to version "33.0.6.1" is available
    And URL to download is "https://download.nextcloud.com/server/releases/nextcloud-33.0.6.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-33.0.6.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-33.0.6.tar.bz2"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v33.0.6/nextcloud-33.0.6.zip"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v33.0.6/nextcloud-33.0.6.tar.bz2"
    And URL to documentation is "https://docs.nextcloud.com/server/33/admin_manual/maintenance/upgrade.html"
    And EOL date is "2027-02-18"
    And The signature is
    """
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000==
    """

  Scenario: Updating Nextcloud 33 on the stable channel
    Given There is a release with channel "stable"
    And The received version is "33.0.0.3"
    And The received PHP version is "8.2.0"
    And the installation mtime is "11"
    When The request is sent
    Then The response is non-empty
    And Update to version "33.0.6.1" is available
    And URL to download is "https://download.nextcloud.com/server/releases/nextcloud-33.0.6.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-33.0.6.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-33.0.6.tar.bz2"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v33.0.6/nextcloud-33.0.6.zip"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v33.0.6/nextcloud-33.0.6.tar.bz2"
    And URL to documentation is "https://docs.nextcloud.com/server/33/admin_manual/maintenance/upgrade.html"
    And EOL date is "2027-02-18"
    And The signature is
    """
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000==
    """
