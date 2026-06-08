Feature: Testing the update scenario of beta releases

  Scenario: Updating Nextcloud latest 32 to 33 on the beta channel
    Given There is a release with channel "beta"
    And The received version is "32.0.11.1"
    And The received PHP version is "8.2.0"
    And the installation mtime is "11"
    When The request is sent
    Then The response is non-empty
    And Update to version "33.0.5.1" is available
    And URL to download is "https://download.nextcloud.com/server/releases/nextcloud-33.0.5.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-33.0.5.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-33.0.5.tar.bz2"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v33.0.5/nextcloud-33.0.5.zip"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v33.0.5/nextcloud-33.0.5.tar.bz2"
    And URL to documentation is "https://docs.nextcloud.com/server/33/admin_manual/maintenance/upgrade.html"
    And EOL date is "2027-02-18"
    And The signature is
    """
    srq41voRX6MJhV4uKhKD63uZMYQ0OVrTfdC2aKEY6st/dVJtEa9Us5Gs8HCS0sJ0
    o5TzKh6vQ7ETyugEWoussxlLrdbsbkAj4D48zF4MOjhxVcsZd7SybTQkzVtd7JVC
    Rr4RdAdARd9PAGJrTDWQlDi2SW+F0T9ZpZ44rlesWLWTH2OZlIsQLFGKzcuO21Xi
    yDdkbvVmX9MdxwlFmxtlEpPliZNEVzJ0yBv9uH/je2WnRk8u2Evu5VS1I2iTfRoC
    p24a00mGWzUJJVD2hMVktzBecm0ffoI0Phe1SngOqljAo5r5LCiqKswtDjD2Cfd+
    Q36WWyhFw7is33ZSGoRW8w==
    """

  Scenario: Updating Nextcloud 33 on the beta channel
    Given There is a release with channel "beta"
    And The received version is "33.0.0.0"
    And The received PHP version is "8.2.0"
    And the installation mtime is "11"
    When The request is sent
    Then The response is non-empty
    And Update to version "33.0.5.1" is available
    And URL to download is "https://download.nextcloud.com/server/releases/nextcloud-33.0.5.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-33.0.5.zip"
    And Download URLS contain "https://download.nextcloud.com/server/releases/nextcloud-33.0.5.tar.bz2"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v33.0.5/nextcloud-33.0.5.zip"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v33.0.5/nextcloud-33.0.5.tar.bz2"
    And URL to documentation is "https://docs.nextcloud.com/server/33/admin_manual/maintenance/upgrade.html"
    And EOL date is "2027-02-18"
    And The signature is
    """
    srq41voRX6MJhV4uKhKD63uZMYQ0OVrTfdC2aKEY6st/dVJtEa9Us5Gs8HCS0sJ0
    o5TzKh6vQ7ETyugEWoussxlLrdbsbkAj4D48zF4MOjhxVcsZd7SybTQkzVtd7JVC
    Rr4RdAdARd9PAGJrTDWQlDi2SW+F0T9ZpZ44rlesWLWTH2OZlIsQLFGKzcuO21Xi
    yDdkbvVmX9MdxwlFmxtlEpPliZNEVzJ0yBv9uH/je2WnRk8u2Evu5VS1I2iTfRoC
    p24a00mGWzUJJVD2hMVktzBecm0ffoI0Phe1SngOqljAo5r5LCiqKswtDjD2Cfd+
    Q36WWyhFw7is33ZSGoRW8w==
    """

  Scenario: Updating Nextcloud latest 33 to 34 on the beta channel
    Given There is a release with channel "beta"
    And The received version is "33.0.5.1"
    And The received PHP version is "8.2.0"
    And the installation mtime is "11"
    When The request is sent
    Then The response is non-empty
    And Update to version "34.0.0.12" is available
    And URL to download is "https://download.nextcloud.com/server/prereleases/nextcloud-34.0.0rc6.zip"
    And Download URLS contain "https://download.nextcloud.com/server/prereleases/nextcloud-34.0.0rc6.zip"
    And Download URLS contain "https://download.nextcloud.com/server/prereleases/nextcloud-34.0.0rc6.tar.bz2"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v34.0.0rc6/nextcloud-34.0.0rc6.zip"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v34.0.0rc6/nextcloud-34.0.0rc6.tar.bz2"
    And URL to documentation is "https://docs.nextcloud.com/server/34/admin_manual/maintenance/upgrade.html"
    And EOL is set to "0"
    And The signature is
    """
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000==
    """

  Scenario: Updating Nextcloud 34 on the beta channel
    Given There is a release with channel "beta"
    And The received version is "34.0.0.0"
    And The received PHP version is "8.2.0"
    And the installation mtime is "11"
    When The request is sent
    Then The response is non-empty
    And Update to version "34.0.0.12" is available
    And URL to download is "https://download.nextcloud.com/server/prereleases/nextcloud-34.0.0rc6.zip"
    And Download URLS contain "https://download.nextcloud.com/server/prereleases/nextcloud-34.0.0rc6.zip"
    And Download URLS contain "https://download.nextcloud.com/server/prereleases/nextcloud-34.0.0rc6.tar.bz2"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v34.0.0rc6/nextcloud-34.0.0rc6.zip"
    And Download URLS contain "https://github.com/nextcloud-releases/server/releases/download/v34.0.0rc6/nextcloud-34.0.0rc6.tar.bz2"
    And URL to documentation is "https://docs.nextcloud.com/server/34/admin_manual/maintenance/upgrade.html"
    And EOL is set to "0"
    And The signature is
    """
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000000000000000000000000000000000000000000000
    TestZIPSig000000000000==
    """
