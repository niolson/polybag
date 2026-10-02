# UPS Shipping API Reference Guide

> **Source:** https://developer.ups.com/api/reference/shipping/  
> **Purpose:** Reference data for integrating with the UPS Shipping API (service codes, package types, country codes, accessorial codes, etc.)

---

## Table of Contents

- [Shipping Business Rules](#shipping-business-rules)
- [Appendix 1 - Reference Codes & Tables](#appendix-1---reference-codes--tables)
  - [Accessorial / Surcharge Codes](#accessorial-surcharge-codes)
  - [Roadie Accessorial & Surcharge Subtypes](#roadie-accessorial-surcharge-subtypes)
  - [COD Supported Countries or Territories](#cod-supported-countries-or-territories)
  - [Country or Territory Codes](#country-or-territory-codes)
  - [Credit Card Types](#credit-card-types)
  - [Currency Codes](#currency-codes)
  - [Delivery Confirmation Origin-Destination Pairs](#delivery-confirmation-origindestination-pairs)
  - [Disclaimer Codes and Messages](#disclaimer-codes-and-messages)
  - [EEI License Codes](#eei-license-codes)
  - [EEI License Types and Exemptions](#eei-license-types-and-exemptions)
  - [ScheduleB Unit of Measure Codes](#scheduleb-unit-of-measure-codes)
  - [Product Unit of Measure Codes](#product-unit-of-measure-codes)
  - [Hong Kong SAR, China District Codes](#hong-kong-sar-china-district-codes)
  - [International Forms Preference Criteria](#international-forms-preference-criteria)
- [Appendix 2 - Additional Reference Data](#appendix-2---additional-reference-data)
  - [Language / Dialect Combinations](#language-dialect-combinations)
  - [License Exception Codes](#license-exception-codes)
  - [Mail Innovations Package Detail](#mail-innovations-package-detail)
  - [Paperless Invoice Support Countries or Territories](#paperless-invoice-support-countries-or-territories)
  - [Reference Number Codes](#reference-number-codes)
  - [Service Codes](#service-codes)
  - [Tax Type Values / Abbreviations](#tax-type-values-abbreviations)
  - [Third Party/Freight Collect Supported Countries or Territories](#third-partyfreight-collect-supported-countries-or-territories)
  - [SubVersion Details](#subversion-details)
  - [Supported Locales](#supported-locales)
  - [Worldwide Economy](#worldwide-economy)
  - [UPS Premier - Special Handling Instructions Codes](#ups-premier-special-handling-instructions-codes)

---

## Shipping Business Rules

*Source: https://developer.ups.com/api/reference/shipping/business-rules?loc=en_US*

### Business Processes and Rules

- Elements/tags that are not defined in the interface or do not conform to the interface structure will be ignored by UPS.
- Only users that plan to ship packages manifested, tendered, and delivered by UPS can use the API.
- Any customers/developers abusing or data mining the API will have their access revoked.

### Shipping Rules

- UPS Worldwide Express Freight shipments require a minimum of five labels to be printed for each pallet.
- You can void a shipment from the following origin countries or territories: US, PR and CA.
- Only the first AddressLine is printed on the ShipFrom section of the label. Address Line 1, 2 and 3 will be printed on the ShipTo Address if provided in the request.
- Only forward shipments require that the Shipper's country code (`/Shipment/Shipper/CountryCode`) and the Ship From country code (`/Shipment/ShipFrom/CountryCode`) be the same. Import Control and Return movements can have different country codes.

### API Endpoints

| Environment | Shipping | Void | Label Recovery |
| --- | --- | --- | --- |
| Testing (CIE) | https://wwwcie.ups.com/api/shipments/{version}/ship | https://wwwcie.ups.com/api/shipments/{version}/void/cancel/{id} | https://wwwcie.ups.com/api/labels/{version}/recovery |
| Production | https://onlinetools.ups.com/api/shipments/{version}/ship | https://onlinetools.ups.com/api/shipments/{version}/void/cancel/{id} | https://onlinetools.ups.com/api/labels/{version}/recovery |

### Void Shipment Test Numbers (CIE only)

| Number(s) | Expected Result |
| --- | --- |
| 1ZISDE016691676846 | Successful shipment level void |
| 1Z2220060290602143 | Successful shipment level void |
| 1Z2220060294314162 + Tracking 1Z2220060291994175 | Successful package level void |
| 1Z2220060292690189 + Tracking 1Z2220060292002190 | Successful package level void |
| 1ZISDE016691609089 + Tracking 1ZISDE016694068891, 1ZISDE016690889305 | Successful package level void (all packages) |
| 1Z2220060290530202 + Tracking 1Z2220060293874210, 1Z2220060292634221 | Partial void - one package voided, one cannot be voided |

### Label Recovery Test Numbers (CIE only)

| Number(s) | Scenario | Expected Result |
| --- | --- | --- |
| 1Z12345E8791315509 | Label returned in PDF format | Request processed, label returned in PDF |
| 1Z12345E8791315413 | Label returned in html format | Request processed, label returned in html |
| 1ZTESTAN0154809878 | UPS Premier Silver Shipment - ZPL | Label returned in ZPL format |
| 1ZTESTAN8480418532 | UPS Premier Gold Return Shipment - GIF/PDF | Label returned in html and PDF |
| 1ZTESTAN0150617707 | UPS Premier Platinum Shipment - GIF/PDF | Label returned in html and PDF |

### Negotiated Rates

The Shipping API provides access to Published Rates and Negotiated Rates. A 1% discount is applied to negotiated rates in the CIE test environment.

To enable negotiated rates:
1. Add your account to your My UPS profile using one of your most recent three invoices.
2. Include the correct My UPS ID/PW + Account + Access Key when transacting with UPS API servers.
3. Include the `NegotiatedRatesIndicator` element (empty tag) within your request.

### Simple Rate Box Sizes

| Size Code | Size Name | Volume Range |
| --- | --- | --- |
| XS | Extra Small | 1 to 100 cu in³ |
| S | Small | 101 to 250 cu in³ |
| M | Medium | 251 to 650 cu in³ |
| L | Large | 651 to 1050 cu in³ |
| XL | Extra Large | 1051 to 1728 cu in³ |

Simple Rate valid services: UPS Ground (03), UPS 3 Day Select (12), UPS 2nd Day Air (02), UPS Next Day Air Saver (13). U.S. 50 States only. Max 50 lbs. Exempt from residential, delivery area, and fuel surcharges.

### Mail Innovations Returns Service Code

Service Code: `M7`

Valid return types: Print Return Label (PRL), Print and Mail Label (PNM), Electronic Return Label (ERL)

---

## Appendix 1 - Reference Codes & Tables

*Source: https://developer.ups.com/api/reference/shipping/appendix1?loc=en_US*

#### Accessorial / Surcharge Codes

The following codes correspond to accessorial/surcharges. The codes are returned in the response when requested with the Subversion element.

| Code | Accessorial / Surcharge |
| --- | --- |
| 100 | ADDITIONAL HANDLING |
| 110 | COD |
| 120 | DELIVERY CONFIRMATION |
| 121 | SHIP DELIVERY CONFIRMATION |
| 153 | PKG EMAIL SHIP NOTIFICATION |
| 154 | PKG EMAIL RETURN NOTIFICATION |
| 155 | PKG EMAIL INBOUND RETURN NOTIFICATION |
| 156 | PKG EMAIL QUANTUM VIEW SHIP NOTIFICATION |
| 157 | PKG EMAIL QUANTUM VIEW EXCEPTION NOTIFICATION |
| 158 | PKG EMAIL QUANTUM VIEW DELIVERY NOTIFICATION |
| 165 | PKG FAX INBOUND RETURN NOTIFICATION |
| 166 | PKG FAX QUANTUM VIEW SHIP NOTIFICATION |
| 171 | SHIP EMAIL ERL NOTIFICATION |
| 173 | SHIP EMAIL SHIP NOTIFICATION |
| 174 | SHIP EMAIL RETURN NOTIFICATION |
| 175 | SHIP EMAIL INBOUND RETURN NOTIFICATION |
| 176 | SHIP EMAIL QUANTUM VIEW SHIP NOTIFICATION |
| 177 | SHIP EMAIL QUANTUM VIEW EXCEPTION NOTIFICATION |
| 178 | SHIP EMAIL QUANTUM VIEW DELIVERY NOTIFICATION |
| 179 | SHIP EMAIL QUANTUM VIEW NOTIFY |
| 187 | SHIP UPS ACCESS POINT NOTIFICATION |
| 188 | SHIP EEI FILING NOTIFICATION |
| 189 | SHIP UAP SHIPPER NOTIFICATION |
| 190 | EXTENDED AREA |
| 199 | HAZ MAT |
| 200 | DRY ICE |
| 201 | ISC SEEDS |
| 202 | ISC PERISHABLES |
| 203 | ISC TOBACCO |
| 204 | ISC PLANTS |
| 205 | ISC ALCOHOLIC BEVERAGES |
| 206 | ISC BIOLOGICAL SUBSTANCES |
| 207 | ISC SPECIAL EXCEPTIONS |
| 220 | HOLD FOR PICKUP |
| 240 | ORIGIN CERTIFICATE |
| 250 | PRINT RETURN LABEL |
| 258 | EXPORT LICENSE VERIFICATION |
| 260 | PRINT N MAIL |
| 270 | RESIDENTIAL ADDRESS |
| 280 | RETURN SERVICE 1ATTEMPT |
| 290 | RETURN SERVICE 3ATTEMPT |
| 300 | SATURDAY DELIVERY |
| 310 | SATURDAY INTERNATIONAL PROCESSING FEE |
| 350 | ELECTRONIC RETURN LABEL |
| 372 | QUANTUM VIEW NOTIFY DELIVERY |
| 374 | UPS PREPARED SED FORM |
| 375 | FUEL SURCHARGE |
| 376 | DELIVERY AREA |
| 377 | LARGE PACKAGE |
| 378 | SHIPPER PAYS DUTY TAX |
| 379 | SHIPPER PAYS DUTY TAX UNPAID |
| 380 | EXPRESS PLUS SURCHARGE |
| 400 | INSURANCE |
| 401 | SHIP ADDITIONAL HANDLING |
| 402 | SHIPPER RELEASE |
| 403 | CHECK TO SHIPPER |
| 404 | UPS PROACTIVE RESPONSE |
| 405 | GERMAN PICKUP |
| 406 | GERMAN ROAD TAX |
| 407 | EXTENDED AREA PICKUP |
| 410 | RETURN OF DOCUMENT |
| 430 | PEAK SEASON |
| 431 | PEAK SEASON SURCHARGE - LARGE PACKAGE |
| 432 | PEAK SEASON SURCHARGE - ADDITIONAL HANDLING |
| 440 | SHIP LARGE PACKAGE |
| 441 | CARBON NEUTRAL |
| 442 | PKG QV IN TRANSIT NOTIFICATION |
| 443 | SHIP QV IN TRANSIT NOTIFICATION |
| 444 | IMPORT CONTROL |
| 445 | COMMERCIAL INVOICE REMOVAL |
| 446 | IMPORT CONTROL ELECTRONIC LABEL |
| 447 | IMPORT CONTROL PRINT LABEL |
| 448 | IMPORT CONTROL PRINT AND MAIL LABEL |
| 449 | IMPORT CONTROL ONE PICK UP ATTEMPT LABEL |
| 450 | IMPORT CONTROL THREE PICK UP ATTEMPT LABEL |
| 452 | REFRIGERATION |
| 454 | PAC 1A BOX1 |
| 455 | PAC 3A BOX1 |
| 456 | PAC 1A BOX2 |
| 457 | PAC 3A BOX2 |
| 458 | PAC 1A BOX3 |
| 459 | PAC 3A BOX3 |
| 460 | PAC 1A BOX4 |
| 461 | PAC 3A BOX4 |
| 462 | PAC 1A BOX5 |
| 463 | PAC 3A BOX5 |
| 464 | EXCHANGE PRINT RETURN LABEL |
| 465 | EXCHANGE FORWARD |
| 466 | SHIP PREALERT NOTIFICATION |
| 470 | COMMITTED DELIVERY WINDOW |
| 480 | SECURITY SURCHARGE |
| 492 | CUSTOMER TRANSACTION FEE |
| 500 | SHIPMENT COD |
| 510 | LIFT GATE FOR PICKUP |
| 511 | LIFT GATE FOR DELIVERY |
| 512 | DROP OFF AT UPS FACILITY |
| 515 | UPS PREMIUM CARE |
| 520 | OVERSIZE PALLET |
| 524 | MI DUAL LABEL RETURN |
| 530 | FREIGHT DELIVERY SURCHARGE |
| 531 | FREIGHT PICKUP SURCHARGE |
| 540 | DIRECT TO RETAIL |
| 541 | DIRECT DELIVERY ONLY |
| 542 | DELIVER TO ADDRESSEE ONLY |
| 543 | DIRECT TO RETAIL COD |
| 544 | RETAIL ACCESS POINT |
| 545 | SHIPPING TICKET NOTIFICATION |
| 546 | ELECTRONIC PACKAGE RELEASE AUTHENTICATION |
| 547 | PAY AT STORE |
| 548 | ICOD NOTIFICATION |
| 550 | ITEM DISPOSAL |
| 551 | UK BORDER FEE |
| 552 | MASTER CARTON |
| 553 | SIMPLE RATE ACCESSORIAL |
| 555 | UPS PREMIER GOLD |
| 556 | UPS PREMIER SILVER |
| 557 | UPS PREMIER PLATINUM |
| 558 | DDU OVERSIZE |
| 573 | INTERNATIONAL PROCESS FEE |

###### Accessorial & Surcharge Subtypes

| Accessorial/Surcharge | SubType |
| --- | --- |
| Freight Delivery Area Surcharge | Freight Delivery Area Surcharge Freight Delivery Area Surcharge Extended Freight Remote Area Surcharge Freight Remote Area Surcharge Extended |
| Freight Pickup Area Surcharge | Freight Pickup Area Surcharge Freight Pickup Area Surcharge Extended Freight Remote Pickup Area Surcharge Freight Remote Pickup Area Surcharge Extended |
| Insurance Accessorial | BPI DVS EVS TNT EPI |
| Ship Delivery Confirmation Accessorial | Adult Signature Required Signature Required |
| Package Delivery Confirmation Accessorial | Name and Date Required Signature and Date Required Adult Signature Required |
| Extended Area Surcharge | Alaska Hawaii Urban Rural Super Rural Super Urban Congested Suburban Extended Area Destination Base Remote Super Remote |
| Delivery Area Surcharge | Alaska Extended Hawaii Extended Urban Rural Rural Extended Metropolitan Congested Area Suburban |
| Extended Area Pickup Surcharge | Alaska Hawaii Urban Rural Super Rural Super Urban Congested Suburban Extended Area Origin |
| Peak Season Surcharge | Residentail_Seasonal_Surcharge Commercial_Seasonal_Surcharge CWT_ Residentail_Seasonal_Surcharge CWT_ Commercial_Seasonal_Surcharge |
| Large Package Surcharge | Residential Commercial Longest side Residential Longest side Commercial |
| Additional Handling Surcharge | Weight |
| Inside Delivery | White Glove Room of Choice Installation |

#### Roadie Accessorial & Surcharge Subtypes

The following codes correspond to Roadie Accessorial & Surcharge Subtypes.

| Accessorial/Surcharge | SubType |
| --- | --- |
| DELIVERY CONFIRMATION | Delivery Confirmation Signature Required Delivery Confirmation Adult Signature Required |
| INSIDE DELIVERY | White Glove Room of Choice Installation Over Threshold Fee |
| INSURANCE ACCESSORIAL | EVS (INSURED_VALUE) |
| NON PREMIUM SUNDAY CHARGE | SaturdayDelivery NSA(NONPREMIUMCOMMERCIALSATURDAYDELIVERY) |
| SURE SURCHARGE FEE | PFR (SurgeFee_Type_Residential) |
| PEAK SEASON | PSR(Residentail_Seasonal_Surcharge) |

#### COD Supported Countries or Territories

Rating and Shipping Package COD supported countries or territories

###### Shipment Level

| Country or Territory | 1 Cash | 9 Check Cashier's Check Money Order |
| --- | --- | --- |
| All European Union (EU) Countries or Territories supported by the API, exceptions noted below.  For additional information, refer to Country or Territory Codes in the Appendix. | Yes | Yes |
| Russia | Yes | No |
| United Arab Emirates | Yes | No |

###### Package Level

| Code | Description |
| --- | --- |
| NOTE | No EU countries or territories currently support Package level COD. |

| Country or territory | 0 Check, Cash Cashier's Check Money Order | 8 Cashier's Check Money Order | 9 Personal Check |
| --- | --- | --- | --- |
| Argentina (AR) |  |  | Yes |
| Brazil (BR) |  |  | Yes |
| Canada (CA) | Yes | Yes |  |
| Chile (CL) |  |  | Yes |
| Mexico (MX) | Yes |  |  |
| Puerto Rico (PR) | Yes | Yes |  |
| United States (US) | Yes | Yes |  |

#### Country or Territory Codes

###### Rating and Shipping Package API Supported Countries or Territories

UPS country or territory code abbreviations generally follow the recommendations of the International Standards Organization (ISO), which publishes a list of country or territory abbreviations in ISO Standard 3166.
The following table lists the country or territory codes defined by ISO at the time of this publication. The latest information is available from the ISO web site: http://www.iso.org/ [iso.org] .

| Code | Description |
| --- | --- |
| NOTE | Not all UPS services are available in every country or territory. Refer to the UPS Rate and Service Guide at UPS.com for more information on UPS services. |

| Destination Country or Territory Name | Country or Territory Code | Supported Forward Origin | Supported Return Origin |
| --- | --- | --- | --- |
| Afghanistan | AF | X |  |
| Aland Islands | AX | X |  |
| Albania | AL | X | X |
| Algeria | DZ | X | X |
| American Samoa | AS |  |  |
| Andorra | AD |  |  |
| Angola | AO | X |  |
| Anguilla | AI |  |  |
| Antarctica | AQ |  |  |
| Antigua and Barbuda | AG | X | X |
| Argentina | AR | X | X |
| Armenia | AM | X | X |
| Aruba | AW | X | X |
| Australia | AU | X | X |
| Austria | AT | X | X |
| Azerbaijan | AZ | X | X |
| Azores | A2 | X |  |
| Bahamas | BS | X | X |
| Bahrain | BH | X | X |
| Bangladesh | BD | X | X |
| Barbados | BB | X | X |
| Belarus | BY |  | X |
| Belgium | BE | X | X |
| Belize | BZ |  |  |
| Benin | BJ |  |  |
| Bermuda | BM | X | X |
| Bhutan | BT |  |  |
| Bolivia (Plurinational State of) | BO | X | X |
| Bonaire, St. Eustatius, Saba | BQ | X | X |
| Bosnia and Herzegovina | BA | X | X |
| Botswana | BW |  |  |
| Bouvet Island | BV |  |  |
| Brazil | BR | X | X |
| British Indian Ocean Territory | IO |  |  |
| Brunei Darussalam | BN | X | X |
| Bulgaria | BG | X | X |
| Burkina Faso | BF |  |  |
| Burundi | BI | X | X |
| Cambodia | KH | X | X |
| Cameroon | CM | X |
| Canada | CA | X | X |
| Canary Islands | IC | X | X |
| Cabo Verde | CV |  |  |
| Cayman Islands | KY | X | X |
| Central African Republic | CF | X | X |
| Ceuta | XC | X |
| Chad | TD | X | X |
| Chile | CL | X | X |
| China Mainland | CN | X | X |
| Christmas Island | CX |  |  |
| Cocos (Keeling) Islands | CC |  |  |
| Colombia | CO | X | X |
| Comoros | KM |  |  |
| Congo | CG |  |  |
| Congo, The Democratic Republic of | CD | X | X |
| Cook Islands | CK |  |  |
| Costa Rica | CR | X | X |
| Cote d' Ivoire (Ivory Coast) | CI | X | X |
| Croatia | HR | X | X |
| Cuba | CU |  |  |
| Curacao | CW | X | X |
| Cyprus | CY | X | X |
| Czech Republic | CZ | X | X |
| Denmark | DK | X | X |
| Djibouti | DJ | X | X |
| Dominica | DM |  |  |
| Dominican Republic | DO | X | X |
| Ecuador | EC | X | X |
| Egypt | EG | X | X |
| El Salvador | SV | X | X |
| England | EN | X | X |
| Equatorial Guinea | GQ |  |  |
| Eritrea | ER |  |  |
| Estonia | EE | X | X |
| Ethiopia | ET | X | X |
| Falkland Islands (Malvinas) | FK |  |  |
| Faroe Islands | FO |  |  |
| Fiji | FJ | X | X |
| Finland | FI | X | X |
| France | FR | X | X |
| French Guiana | GF |  |  |
| French Polynesia | PF |  |  |
| French Southern Territories | TF |  |  |
| Gabon | GA |  |  |
| Gambia | GM |  |  |
| Georgia | GE | X | X |
| Germany | DE | X | X |
| Ghana | GH | X | X |
| Gibraltar | GI | X | X |
| Greece | GR | X | X |
| Greenland | GL |  |  |
| Grenada | GD |  |  |
| Guadeloupe | GP |  |  |
| Guam | GU | X | X |
| Guatemala | GT | X | X |
| Guernsey | GG | X | X |
| Guinea | GN | X | X |
| Guinea-Bissau | GW |  |  |
| Guyana | GY |  |  |
| Haiti | HT |  | X |
| Heard Island and McDonald Islands | HM |  |  |
| Holland | HO | X |  |
| Holy See (See Vatican) |  |  |  |
| Honduras | HN | X | X |
| Hong Kong SAR, China | HK | X | X |
| Hungary | HU | X | X |
| Iceland | IS | X | X |
| India | IN | X | X |
| Indonesia | ID | X | X |
| Iran (Islamic Republic of) | IR |  |  |
| Iraq | IQ | X | X |
| Ireland | IE | X | X |
| Isle of Man | IM |  |  |
| Israel | IL | X | X |
| Italy | IT | X | X |
| Jamaica | JM | X | X |
| Japan | JP | X | X |
| Jersey | JE | X | X |
| Jordan | JO | X | X |
| Kazakhstan | KZ | X | X |
| Kenya | KE | X | X |
| Kiribati | KI |  |  |
| Korea (Democratic People's Republic of) | KP |  |  |
| Korea, South | KR | X | X |
| Kosovo | KV | X |  |
| Kosrae | KO |  |  |
| Kuwait | KW | X | X |
| Kyrgyzstan | KG | X |  |
| Lao People's Democratic Republic (Laos) | LA | X | X |
| Latvia | LV | X | X |
| Lebanon | LB | X | X |
| Lesotho | LS |  |  |
| Liberia | LR |  |  |
| Libya | LY | X | X |
| Liechtenstein | LI | X | X |
| Lithuania | LT | X | X |
| Luxembourg | LU | X | X |
| Macau SAR, China | MO | X | X |
| Macedonia (FYROM) | MK | X | X |
| Madagascar | MG | X | X |
| Madeira | A3 | X |  |
| Malawi | MW | X | X |
| Malaysia | MY | X | X |
| Maldives | MV |  |  |
| Mali | ML | X | X |
| Malta | MT | X | X |
| Marshall Islands | MH |  |  |
| Martinique | MQ |  |  |
| Mauritania | MR | X | X |
| Mauritius | MU | X | X |
| Mayotte | YT |  |  |
| Melilla | XL | X |  |
| Mexico | MX | X | X |
| Micronesia (Federated States of) | FM |  |  |
| Moldova (Republic of) | MD | X |  |
| Monaco | MC | X | X |
| Mongolia | MN |  |  |
| Montenegro | ME | X | X |
| Montserrat | MS |  |  |
| Morocco | MA | X | X |
| Mozambique | MZ | X |  |
| Myanmar | MM |  |  |
| Namibia | NA |  |  |
| Nauru | NR |  |  |
| Nepal | NP | X |  |
| Netherlands | NL | X | X |
| New Caledonia | NC |  |  |
| New Zealand | NZ | X | X |
| Nicaragua | NI | X | X |
| Niger | NE |  |  |
| Nigeria | NG | X | X |
| Norfolk Island | NF |  |  |
| Northern Ireland | NB | X |  |
| Northern Mariana Islands | MP |  |  |
| Norway | NO | X | X |
| Oman | OM | X | X |
| Pakistan | PK | X | X |
| Palau | PW |  |  |
| Palestine, State of | PS |  |  |
| Panama | PA | X | X |
| Papua New Guinea | PG |  |  |
| Paraguay | PY | X | X |
| Peru | PE | X | X |
| Philippines | PH | X | X |
| Pitcairn | PN |  |  |
| Poland | PL | X | X |
| Ponape | PO |  |  |
| Portugal | PT | X | X |
| Puerto Rico | PR | X | X |
| Qatar | QA | X | X |
| Reunion | RE | X | X |
| Romania | RO | X | X |
| Russia (Russian Federation) | RU | X | X |
| Rwanda | RW | X | X |
| Saint Barthelemy | BL |  |  |
| Saint Christopher | SW | X | X |
| Saint Croix (see Virgin Islands) | C3 | X |  |
| Saint John | UV | X |  |
| Saint Kitts and Nevis | KN | X | X |
| Saint Lucia | LC | X | X |
| Saint Maarten and St. Martin | SX | X | X |
| Saint Thomas | VL | X | X |
| Saint Vincent and the Grenadines | VC |  |  |
| Saipan | SP |  |  |
| Samoa | WS |  |  |
| San Marino | SM |  |  |
| Sao Tome and Principe | ST |  |  |
| Saudi Arabia | SA | X | X |
| Scotland | SF | X |  |
| Senegal | SN | X | X |
| Serbia | RS | X | X |
| Seychelles | SC |  |  |
| Sierra Leone | SL |  |  |
| Singapore | SG | X | X |
| Slovakia | SK | X | X |
| Slovenia | SI | X | X |
| Solomon Islands | SB |  |  |
| South Africa | ZA | X | X |
| Spain | ES | X | X |
| Sri Lanka | LK | X | X |
| Suriname | SR |  |  |
| Swaziland | SZ |  |  |
| Sweden | SE | X | X |
| Switzerland | CH | X | X |
| Tahiti | TA |  |  |
| Taiwan, China | TW | X | X |
| Tajikistan | TJ |  |  |
| Tanzania (United Republic of) | TZ | X | X |
| Thailand | TH | X | X |
| Timor-Leste | TL |  |  |
| Tinian | TI |  |  |
| Togo | TG |  |  |
| Tonga | TO |  |  |
| Tortola | ZZ |  |  |
| Trinidad and Tobago | TT | X | X |
| Truk | TU |  |  |
| Tunisia | TN | X | X |
| Turkey | TR | X | X |
| Turkmenistan | TM |  |  |
| Turks and Caicos Islands | TC |  |  |
| Tuvalu | TV |  |  |
| Uganda | UG | X |  |
| Ukraine | UA | X | X |
| Union Island | UI |  |  |
| United Arab Emirates | AE | X | X |
| United Kingdom | GB | X | X |
| United States | US |  |  |
| Uruguay | UY | X | X |
| Uzbekistan | UZ | X | X |
| Vanuatu | VU |  |  |
| Vatican City State | VA | X | X |
| Venezuela (Bolivarian Republic of) | VE | X | X |
| Vietnam (Viet Nam) | VN | X | X |
| Virgin Islands, British | VG |  |  |
| Virgin Islands, US | VI | X | X |
| Wales | WL | X | X |
| Wallis and Futuna Islands | WF |  |  |
| Yap | YA |  |  |
| Yemen | YE |  |  |
| Zambia | ZM | X | X |
| Zimbabwe | ZW | X |  |

#### Credit Card Types

| Code | Description |
| --- | --- |
| 01 | American Express |
| 03 | Discover |
| 04 | MasterCard |
| 05 | Optima |
| 06 | VISA |
| 07 | Bravo |
| 08 | Diners Club |
| 13 | Dankort |
| 14 | Hipercard |
| 15 | JCB |
| 17 | Postepay |
| 18 | UnionPay/ExpressPay |
| 19 | Visa Electron |
| 20 | VPAY |
| 21 | Carte Bleue |

#### Currency Codes

UPS currency code abbreviations generally follow the recommendations of the International Standards Organization (ISO), which publishes a list of currency abbreviations in ISO Standard 4217. The following table lists the currency codes defined by ISO at the time of this publication. The latest information is available from the ISO web site: http://www.iso.org/ [iso.org] .

Countries or Territories may sometimes change their official currency. UPS does require time after the introduction of a new currency before it can fully support that currency. In addition, UPS may continue to support the older currency for an interim period in order to provide backwards compatibility.

UPS may also require the use of currencies other than the official currency for some countries or territories.

| Country or Territory or Region | Currency Name | Currency Code |
| --- | --- | --- |
| Afghanistan | Afghani | AFN |
| Albania | Lek | ALL |
| Algeria | Algerian Dinar | DZD |
| American Samoa | US Dollar | USD |
| Andorra | Euro | EUR |
| Angola | Kwanza | AOA |
| Anguilla | East Caribbean Dollar | XCD |
| Antigua And Barbuda | East Caribbean Dollar | XCD |
| Argentina | Argentine Peso | ARS |
| Armenia | Armenian Dram | AMD |
| Aruba | Aruban Guilder | AWG |
| Australia | Australian Dollar | AUD |
| Austria | Euro | EUR |
| Azerbaijan | Azerbaijanian Manat | AZN |
| Bahamas | Bahamian Dollar | BSD |
| Bahrain | Bahraini Dinar | BHD |
| Bangladesh | Taka | BDT |
| Barbados | Barbados Dollar | BBD |
| Belarus | Belarussian Ruble | BYR |
| Belgium | Euro | EUR |
| Belize | Belize Dollar | BZD |
| Benin | CFA Franc BCEAO | XOF |
| Bermuda | Bermudian Dollar | BMD |
| Bhutan | Indian Rupee | INR |
| Bhutan | Ngultrum | BTN |
| Bolivia | Boliviano | BOB |
| Bolivia | Mvdol | BOV |
| Bosnia and Herzegovina | Convertible Marks | BAM |
| Botswana | Pula | BWP |
| Bouvet Island | Norwegian Krone | NOK |
| Brazil | Brazilian Real | BRL |
| British Indian Ocean Territory | US Dollar | USD |
| Brunei Darussalam | Brunei Dollar | BND |
| Bulgaria | Bulgarian Lev | BGN |
| Burkina Faso | CFA Franc BCEAO | XOF |
| Burundi | Burundi Franc | BIF |
| Cambodia | Riel | KHR |
| Cameroon | US Dollar | USD |
| Canada | Canadian Dollar | CAD |
| Cape Verde | Cape Verde Escudo | CVE |
| Cayman Islands | Cayman Islands Dollar | KYD |
| Central African Republic | CFA Franc BEAC | XAF |
| Chad | CFA Franc BEAC | XAF |
| Chile | Chilean Peso | CLP |
| Chile | Unidades de formento | CLF |
| China Mainland | Yuan Renminbi | RMB |
| Christmas Island | Australian Dollar | AUD |
| Cocos (Keeling) Islands | Australian Dollar | AUD |
| Colombia | Colombian Peso | COP |
| Colombia | Unidad de Valor Real | COU |
| Comoros | Comoro Franc | KMF |
| Congo | CFA Franc BEAC | XAF |
| Congo, The Democratic Republic of | Franc Congolais | CDF |
| Cook Islands | New Zealand Dollar | NZD |
| Costa Rica | Costa Rican Colon | CRC |
| C��te Divoire | CFA Franc BCEAO | XOF |
| Croatia | Croatian Kuna | HRK |
| Cuba | Cuban Peso | CUP |
| Cyprus | Euro | EUR |
| Czech Republic | Czech Koruna | CZK |
| Denmark | Danish Krone | DKK |
| Djibouti | Djibouti Franc | DJF |
| Dominica | East Caribbean Dollar | XCD |
| Dominican Republic | Dominican Peso | DOP |
| Ecuador | US Dollar | USD |
| Egypt | Egyptian Pound | EGP |
| El Salvador | El Salvador Colon | SVC |
| El Salvador | US Dollar | USD |
| Equatorial Guinea | CFA Franc BEAC | XAF |
| Eritrea | Nakfa | ERN |
| Estonia | Euro | EUR |
| Ethiopia | Ethiopian Birr | ETB |
| Falkland Islands (Malvinas) | Falkland Islands Pound | FKP |
| Faroe Islands | Danish Krone | DKK |
| Fiji | Fiji Dollar | FJD |
| Finland | Euro | EUR |
| France | Euro | EUR |
| French Guiana | Euro | EUR |
| French Polynesia | CFP Franc | XPF |
| French Southern Territories | Euro | EUR |
| Gabon | CFA Franc BEAC | XAF |
| Gambia | Dalasi | GMD |
| Georgia | Lari | GEL |
| Germany | Euro | EUR |
| Ghana | Cedi | GHS |
| Gibraltar | Gibraltar Pound | GIP |
| Greece | Euro | EUR |
| Greenland | Danish Krone | DKK |
| Grenada | East Caribbean Dollar | XCD |
| Guadeloupe | Euro | EUR |
| Guam | US Dollar | USD |
| Guatemala | Quetzal | GTQ |
| Guernsey | Pound Sterling | GBP |
| Guinea | Guinea Franc | GNF |
| Guinea-Bissau | Guinea-Bissau Peso | GWP |
| Guinea-Bissau | CFA Franc BCEAO | XOF |
| Guyana | Guyana Dollar | GYD |
| Haiti | Gourde | HTG |
| Haiti | US Dollar | USD |
| Heard Island and McDonald Islands | Australian Dollar | AUD |
| Holy See (Vatican City State) | Euro | EUR |
| Honduras | Lempira | HNL |
| Hong Kong SAR, China | Hong Kong Dollar | HKD |
| Hungary | Forint | HUF |
| Iceland | Iceland Krona | ISK |
| India | Indian Rupee | INR |
| Indonesia | Rupiah | IDR |
| Iran (Islamic Republic of) | Iranian Rial | IRR |
| Iraq | Iraqi Dinar | IQD |
| Ireland | Euro | EUR |
| Israel | New Israeli Sheqel | ILS |
| Italy | Euro | EUR |
| Jamaica | Jamaican Dollar | JMD |
| Japan | Yen | JPY |
| Jersey | Pound Sterling | GBP |
| Jordan | Jordanian Dinar | JOD |
| Kazakhstan | Tenge | KZT |
| Kenya | Kenyan Shilling | KES |
| Kiribati | Australian Dollar | AUD |
| Korea, Democratic Peoples Republic of | North Korean Won | KPW |
| Korea, Republic of | Won | KRW |
| Kuwait | Kuwaiti Dinar | KWD |
| Kyrgyzstan | Som | KGS |
| Lao Peoples Democratic Republic | Kip | LAK |
| Latvia | Euro | EUR |
| Lebanon | Lebanese Pound | LBP |
| Lesotho | Rand | ZAR |
| Lesotho | Loti | LSL |
| Liberia | Liberian Dollar | LRD |
| Libyan Arab Jamahiriya | Libyan Dinar | LYD |
| Liechtenstein | Swiss Franc | CHF |
| Lithuania | Euro | EUR |
| Luxembourg | Euro | EUR |
| Macau (Macao) SAR, China | Pataca | MOP |
| Macedonia, The Former Yugoslav Republic | Denar | MKD |
| Madagascar | Malagascy Ariary | MGA |
| Malawi | Kwacha | MWK |
| Malaysia | Malaysian Ringgit | MYR |
| Maldives | Rufiyaa | MVR |
| Mali | CFA Franc BCEAO | XOF |
| Malta | Euro | EUR |
| Marshall Islands | US Dollar | USD |
| Martinique | Euro | EUR |
| Mauritania | Ouguiya | MRO |
| Mauritius | Mauritius Rupee | MUR |
| Mayotte | Euro | EUR |
| Mexico | Mexican Peso | MXN |
| Mexico | Mexican Unidad de Inversion (UID) | MXV |
| Micronesia (Federated States of) | US Dollar | USD |
| Moldova, Republic of | Moldovan Leu | MDL |
| Monaco | Euro | EUR |
| Mongolia | Tugrik | MNT |
| Montenegro | Euro | EUR |
| Montserrat | East Caribbean Dollar | XCD |
| Morocco | Moroccan Dirham | MAD |
| Mozambique | Metical | MZN |
| Myanmar | Kyat | MMK |
| Namibia | Rand | ZAR |
| Namibia | Namibian Dollar | NAD |
| Nauru | Australian Dollar | AUD |
| Nepal | Nepalese Rupee | NPR |
| Netherlands | Euro | EUR |
| Netherlands Antilles | Netherlands Antillian Guilder | ANG |
| New Caledonia | CFP Franc | XPF |
| New Zealand | New Zealand Dollar | NZD |
| Nicaragua | Cordoba Oro | NIO |
| Niger | CFA Franc BCEAO | XOF |
| Nigeria | Naira | NGN |
| Niue | New Zealand Dollar | NZD |
| Norfolk Island | Australian Dollar | AUD |
| Northern Mariana Islands | US Dollar | USD |
| Norway | Norwegian Krone | NOK |
| Oman | Rial Omani | OMR |
| Pakistan | Pakistan Rupee | PKR |
| Palau | US Dollar | USD |
| Panama | Balboa | PAB |
| Panama | US Dollar | USD |
| Papua New Guinea | Kina | PGK |
| Paraguay | Guarani | PYG |
| Peru | Nuevo Sol | PEN |
| Philippines | Philippine Peso | PHP |
| Pitcairn | New Zealand Dollar | NZD |
| Poland | Zloty | PLN |
| Portugal | Euro | EUR |
| Puerto Rico | US Dollar | USD |
| Qatar | Qatari Rial | QAR |
| R��union | Euro | EUR |
| Romania | New Leu | RON |
| Russian Federation | Russian Ruble | RUB |
| Rwanda | Rwanda Franc | RWF |
| Saint Helena | Saint Helena Pound | SHP |
| Saint Kitts and Nevis | East Caribbean Dollar | XCD |
| Saint Lucia | East Caribbean Dollar | XCD |
| Saint Pierre and Miquelon | Euro | EUR |
| Saint Vincent and The Grenadines | East Caribbean Dollar | XCD |
| Samoa | Tala | WST |
| San Marino | Euro | EUR |
| S��o Tome and Principe | Dobra | STD |
| Saudi Arabia | Saudi Riyal | SAR |
| Senegal | CFA Franc BCEAO | XOF |
| Serbia | Serbian Dinar | RSD |
| Seychelles | Seychelles Rupee | SCR |
| Sierra Leone | Leone | SLL |
| Singapore | Singapore Dollar | SGD |
| Slovakia | Euro | EUR |
| Slovenia | Euro | EUR |
| Solomon Islands | Solomon Islands Dollar | SBD |
| Somalia | Somali Shilling | SOS |
| South Africa | Rand | ZAR |
| Spain | Euro | EUR |
| Sri Lanka | Sri Lanka Rupee | LKR |
| Sudan | Sudanese Dinar | SDD |
| Suriname | Surinam Dollar | SRD |
| Svalbard and Jan Mayen | Norwegian Krone | NOK |
| Swaziland | Lilangeni | SZL |
| Sweden | Swedish Krona | SEK |
| Switzerland | Swiss Franc | CHF |
| Switzerland | WIR Franc | CHW |
| Switzerland | WIR Euro | CHE |
| Syrian Arab Republic | Syrian Pound | SYP |
| Taiwan, China | New Taiwan Dollar | TWD |
| Tajikistan | Somoni | TJS |
| Tanzania, United Republic of | Tanzanian Shilling | TZS |
| Thailand | Baht | THB |
| Timor-Leste | US Dollar | USD |
| Togo | CFA Franc BCEAO | XOF |
| Tokelau | New Zealand Dollar | NZD |
| Tonga | Paanga | TOP |
| Trinidad And Tobago | Trinidad and Tobago Dollar | TTD |
| Tunisia | Tunisian Dinar | TND |
| Turkey | New Turkish Lira | TRY |
| Turkmenistan | Manat | TMM |
| Turks And Caicos Islands | US Dollar | USD |
| Tuvalu | Australian Dollar | AUD |
| Uganda | Uganda Shilling | UGX |
| Ukraine | Hryvnia | UAH |
| United Arab Emirates | UAE Dirham | AED |
| United Kingdom | Pound Sterling | GBP |
| United States | US Dollar | USD |
| United States Minor Outlying Islands | US Dollar | USD |
| Uruguay | Peso Uruguayo | UYU |
| Uruguay | Uruguay Peso en Unidades Indexadas | UYI |
| Uzbekistan | Uzbekistan Sum | UZS |
| Vanuatu | Vatu | VUV |
| Venezuela | Bolivar | VEB |
| Viet Nam | Dong | VND |
| Virgin Islands (British) | US Dollar | USD |
| Virgin Islands (US) | US Dollar | USD |
| Wallis And Futuna | CFP Franc | XPF |
| Western Sahara | Moroccan Dirham | MAD |
| Yemen | Yemeni Rial | YER |
| Zambia | Kwacha | ZMK |
| Zimbabwe | Zimbabwe Dollar | ZWD |

#### Delivery Confirmation Origin-Destination Pairs

The Origin-Destination table defines valid origin and destination combinations for the delivery confirmation accessorials. These accessorials may be applied at the package-level (P) or at the shipment-level (S). They are valid for forward shipments only.
Delivery confirmation types are as follows:
*Refer to Country or Territory Codes in the Appendix.

- Delivery confirmation with signature required (DC-SR)
- Delivery confirmation with adult signature required (DC-ASR)

| Origin | Destination | DC-SR | DC-ASR |
| --- | --- | --- | --- |
| US50 | US50, PR | P | P |
| CA, VI | S | S |
| Intl other than CA, PR, VI | S | S |
| Canada (CA) | US50, PR, VI | S | S |
| CA | P | P |
| Intl other than US50, PR, VI | S | S |
| Puerto Rico (PR) | US50, PR | P | P |
| CA, VI | S | S |
| Intl other than US50, CA, VI | S | S |
| International-supported origin countries or territories (not US50, PR, CA, VI) * | International (national, transborder, worldwide) | S | S |

#### Disclaimer Codes and Messages

| Disclaimer Codes | Disclaimer Message |
| --- | --- |
| 01 | Taxes are included in the shipping cost and apply to the transportation charges but additional duties/taxes may apply and are not reflected in the total amount due. |
| 02 | Additional duties/taxes may apply and are not reflected in the total amount due. |
| 03 | Additional duties/taxes may apply and are not reflected in the total amount due. |
| 04 | Taxes were unable to be determined and may apply to the shipment. |
| 05 | Rate excludes VAT. Rate includes a fuel Surcharge, but excludes taxes, duties and other charges that may apply to the shipment. |

#### EEI License Codes

Electronic Export Information (EEI)

###### Department of Commerce/Bureau of Industry and Security (BIS)

##### Column definitions:

| Code | Description |
| --- | --- |
| 1 | License Code |
| 2 | License Description |
| 3 | Report = Export License Nbr / CFR Citation /Authorization Symbol / KPC# |
| 4 | ECCN |
| 5 | Allowed MOT Codes |

| Code | Description | Report | ECCN | Export | MOT |
| --- | --- | --- | --- | --- | --- |
| C30 | Licenses issued by BIS authorizing an export, re-export, or other regulated activity. The term 'license' does not include authority represented by a 'License Exception'. EAR99 may be reported as an ECCN. | Report the License Number. | Mandatory | IW, OS, OI, TL | All |
| C31 | Special Comprehensive License (SCL)  Part 752.  EAR99 may be reported as an ECCN. | Report the License Number. | Mandatory | IW,OS, OI,TL | All |
| C32 | No License Required (NLR) Part 758  Those items which are covered by entries on the Commerce Control List that have a reason for control other than or in addition to AntiTerrorism (AT).  For items under 600 series ECCNs with a .y paragraph, use C60 (DY6).  EAR99 may be reported as an ECCN. | Report 'NLR'. | Mandatory | IW, OS, OI, TL | All except '70' (Fixed Transport) |
| C33 | No License Required (NLR) Part 758  All other NLR items filed under the 'NLR' provisions of the EAR Part 758 that are not covered by C32. Use C33 and report the ECCN if the commodity is controlled ONLY for Anti-Terrorism (AT).  For items under 600 series ECCNs with a .y paragraph, use C60 (DY6).  EAR99 may be reported as an ECCN.  For Census purposes, use C33 for shipments between the U.S. and Puerto Rico and from the U.S. to the U.S. Virgin Islands. | Report 'NLR'. | Allowed | All except UG, FS, FI | All |
| C35 | Limited Value Shipments (LVS) Part 740.3  EAR99 may NOT be reported as an ECCN.  Only allow Countries or Territories of Destination from the country or territory Group B list  Reference: https://www.gpo.gov/fdsys/pkg/CFR-2001-title15-vol2/pdf/CFR-2001-title15-vol2-sec740-6.pdf [gpo.gov] | Report 'LVS'. | Mandatory  Must be one of the following:  0A018  0A918  1A001  1A002  1A003  1A008  1B001  1B002  1B003  1C002  1C003  1C004  1C005  1C007  1C008  1C009  1C010  2B003  2B005  2B007  3C001  3C003  3C004  4A001  4A004  5A002  6A002  6A006  6A007  6B007  6C002  6C005  8A001  8A018  8B001  9A002  9A003  9A018  9B008  9B009  8A918  3A992  5B991  1B018  1C018  2B018  3B002  4A003  1C006  2A001  3A001  3A002  3B001  3C002  5A001  5B001  6A001  6A003  6A004  6A005  6A008  6B004  6C004  8A002  9B001  9B002  9B003  9B004  9B006  9A610  9A619  9B610  9B619  9C610  9C619 | CR, IS, TE, TL, CH, CI, MS, GS, IP, IR, OI,OS, DD, IW | All except '70' (Fixed Transport) |
| C36 | Shipments to B Countries or Territories (GBS) Part 740.4  EAR99 may NOT be reported as an ECCN.  Only allow Countries or Territories of Destination from the country or territory Group B list.  Reference: https://www.gpo.gov/fdsys/pkg/CFR-2001-title15-vol2/pdf/CFR-2001-title15-vol2-sec740-6.pdf [gpo.gov] | Report 'GBS'. | Mandatory  Must be one of the following:  1A005  2B018  3B002  4A003  1C006  2A001  3A001  3A002  3B001  3C002  3C005  3C006  5A001  5B001  6A001  6A003  6A004  6A005  6A008  6B004  6C004  8A002  9B001  9B002  9B003  9B004  9B006 | CR, GP, IS,TE, TL,MS,GS, IP,IR, TP,OI, OS,DD, IW | All except'70' (Fixed Transport) |
| C37 | Civil End Users (CIV) Part 740.5  EAR99 may NOT be reported as an ECCN.  Only allow Countries or Territories of Destination from the country or territory Group D1 list  Reference: https://www.gpo.gov/fdsys/pkg/CFR-2001-title15-vol2/pdf/CFR-2001-title15-vol2-sec740-6.pdf [gpo.gov] | Report 'CIV'. | Mandatory  Must be one of the following:  1D001  1D002  3E002  5D001  6D003  9D003  1C006  2A001  3A001  3A002  3B001  3C002  3C005  3C006  4A003  5A001  5B001  6A001  6A003  6A004  6A005  6A008  6B004  6C004  8A002  9B001  9B002  9B003  9B004  9B006 | CR, IS, TE, TL, IP, IR, OI, OS, DD, IW | All except '70' (Fixed Transport) |
| C38 | Restrict Technology and Software (TSR) Part 740.6 (AES or EEI filing not required)  EAR99 may NOT be reported as an ECCN.  Only allow Countries or Territories of Destination from the country or territory Group B list  Reference: https://www.gpo.gov/fdsys/pkg/CFR-2001-title15-vol2/pdf/CFR-2001-title15-vol2-sec740-6.pdf [gpo.gov] | Report 'TSR'. | Mandatory  Must be one of the following:  1D001  1D002  3E002  5D001  6D003  9D003  1E001  1E002  2D001  2D002  2E001  2E002  2E003  3D001  3D002  3D003  3D004  3E001  3E003  4D002  5E001  6D001  6D002  6E001  6E002  6E003  8D001  8D002  8E001  8E002  9D018  9E018  4D001  4E001  0E018  2D018  2E018 | CR,GP, IS,TE, TL,MS,GS, IP,IR, OI,OS, IW | All except '70' (Fixed Transport) |
| C39 | Computers (CTP) Part 740.7  License Exception CTP has been revised and is now known as License Exception APP. License Code C39 is replaced with License Code C53.  AES will continue to allow corrections/replacements/cancellations to shipments previous accepted in AES under License Exception C39. All new shipments covered by this exception being added to AES must be reported under License Code C53 with Authorization Symbol 'APP'.  Items under 600 series ECCNs are not eligible under this license type.  EAR99 may be reported as an ECCN. |  |  |  |  |
| C40 | Temporary Imports, Exports, and Re-exports (TMP) Part 740.9  Consolidates the following categories: Temporary exports and re-exports; Items temporarily in the U.S.; Beta Test Software.  EAR99 may be reported as an ECCN. | Report 'TMP'. | Allowed | CR,GP, IS,TE, TL,MS,GS, IP,IR, TP,OI, OS,DD, IW | All except '70' (Fixed Transport) |
| C41 | Servicing and Replacement of Parts and  Equipment (RPL) Part 740.10 Consolidates the following categories: Onefor-one replacement of parts; servicing and replacement of equipment.  EAR99 may be reported as an ECCN. | Report 'RPL'. | Mandatory | GP, IS,TE, TL,MS,GS, IP,IR, TP,OI, OS,IW | All except '70' (Fixed Transport) |
| C42 | Government and International Organizations (GOV) Part 740.11 (AES or EEI not required)  Consolidates the following categories: International safeguards; shipments to U.S. Agencies and personnel; shipments to Agencies of cooperating governments.  EAR99 may be reported as an ECCN. | Report 'GOV'. | Mandatory | GP, IS,TE, TL,CH, CI,MS,GS, IP,IR, TP,OI, OS,DD, IW | All except '70' (Fixed Transport) |
| C43 | Gift Parcels and Humanitarian Donations (GFT) Part 740.12  (AES or EEI not required)  Items under 600 series ECCNs are not eligible under this license type.  Consolidates the following categories: Gift parcels; Humanitarian donations.  EAR99 may be reported as an ECCN. | Report 'GFT'. | Mandatory | UG, IW | All except '70' (Fixed Transport) |
| C44 | Technology and Software - Unrestricted (TSU) Part 740.13  Consolidates the following categories: Operating technology and software; Sales technology and software; Software updates; General software.  EAR99 may be reported as an ECCN. | Report 'TSU'. | Mandatory | CR,GP, IS,TE, TL,MS,GS, IP, IR, TP, OI, OS, IW | All except '70' (Fixed Transport) |
| C45 | Baggage (BAG) Part 740.14  Items under 600 series ECCNs are not eligible under this license type.  EAR99 may be reported as an ECCN. | Report 'BAG'. | Mandatory | OI, OS, IW | All except '70' (Fixed Transport) |
| C46 | Aircraft and Vessels (AVS) Part 740.15 (AES or EEI not required)  Items under 600 series ECCNs are not eligible under this license type.  EAR99 may be reported as an ECCN. | Report 'AVS'. | Mandatory | GP, IS, TE, TL, MS, GS, IP, IR, TP, OI, OS, IW | All except '70' (Fixed Transport) |
| C49 | Trans-Alaska Pipeline Authorization Act (TAPS) Part 754.2  Permits the export of Alaskan North Slope crude oil.  Items under 600 series ECCNs are not eligible under this license type.  EAR99 may be reported as an ECCN. | Report 'TAPS'. | Mandatory | OI, OS, IW | '70' (Fixed Transport) |
| C50 | Encryption Commodities and Software (ENC) Part 740.17  Permits the export and re-export of any key length encryption commodities and software after review; permits the export and re-export of any key length encryption to U.S. subsidiaries without review.  EAR99 may NOT be reported as an ECCN. | Report 'ENC'. | Mandatory Must be one of the following:  5A002  5B002  5D002  5E002 | CR, GP, IS, TE, TL, MS, GS, IP, IR, TP, OI, OS, IW | All except '70' (Fixed Transport) |
| C51 | License Exception Agricultural Commodities (AGR) Part 740.18  Authorizes exports and certain re-exports of agricultural commodities to Cuba.  Items under 600 series ECCNs are not eligible under this license type.  EAR99 may be reported as an ECCN. | Report License Number. | Mandatory | CH, OI, OS, IW | All except '20', '21' (Rail), '30', '31' (Truck), '70' (Fixed Transport) |
| C53 | Computers (APP) Part 740.7  Adjusted Peak Performance (APP) replaces Composite Theoretical Performance (CTP)  EAR99 may NOT be reported as an ECCN. | Report 'APP'. | Mandatory Must be one of the following:  4A003  4D001  4E001 | CR, GP, IS, TE, TL, MS, GS, IP, IR, TP, OI, OS, IW | All except '70' (Fixed Transport) |
| C54 | Short Supply (Western Red Cedar - WRC) Part 754.4  EAR99 may NOT be reported as an ECCN. | Report 'SS-WRC' | Mandatory | Must be 1C988 | All | All except '70' (Fixed Transport) |
| C55 | Short Supply (Crude Oil Samples - SAMPLE) Part 754.2  EAR99 may NOT be reported as an ECCN. | Report 'SS-SAMPLE' | Mandatory Must be 1C981 | All | All |
| C56 | Short Supply (Strategic Petroleum Reserves - SPR) Part 754.2  EAR99 may NOT be reported as an ECCN. | Report 'SS-SPR'. | Mandatory Must be 1C981 | All | All |
| C57 | Authorization for Validated End-User for Certain Authorized Exports and Re-exports of Commerce Control List items to the People's Republic of China and India.  Items under 600 series ECCNs are not eligible under this license type.  EAR99 may NOT be reported as an ECCN. | Report 'VEU'. | Mandatory | OI, OS,0 TL, IW | All except '70' (Fixed Transport) |
| C58 | Free Exchange of Information allows export and re-export of consumer products related to communications and exchange of information to Cuba. | Report 'CCD' | 4A994, 4D994, 5A991, 5D991, 5D992,5A992,  EAR99 | OI, OS, CH, CI | All except '70' (Fixed Transport) |
| C59 | Strategic Trade Authorization (STA) allows an exception to export specific controlled items to certain countries or territories that would otherwise require a license because of CCLbased license requirements. | Report 'STA' | 600-series ECCNs and other ECCNs are eligible to the extent permitted under part 740.20 of the EAR | OI, OS, CH, CI | All |
| C60 | .y "600 series" items to identify the .y subparts to ECCNs that are in the "600 series" because they have less military significance than other subparts to the "600 series." | Report 'DY6' | Allowed  When reported, the ECCN must be one of the following:  9A610  9A619  9B619  9D610  9D619  9E610  9E619 | OI, OS, CH, CI | All |

###### Department of Energy/National Nuclear Security Administration (DOE/NNSA)

| Code | Description | Report | ECCN | Export | MOT |
| --- | --- | --- | --- | --- | --- |
| E01 | Authorization for Nuclear Security Enterprise of government-owned, contractor-operated (GOCO) Management and Operations entities that perform work under the directions and oversight of the National Nuclear Security Administration, to export items authorized by the Atomic Energy Act Authorization/Licensing and as acknowledged in the International Traffic in Arms Regulations section 123.20 and 125.1. | Report 'AEA' | Not Allowed | OS | All except 12,20, 21, 70 |

###### Nuclear Regulatory Commission (NRC)

| Code | Description | Report | ECCN | Export | MOT |
| --- | --- | --- | --- | --- | --- |
| N01 | NRC Form 250/250A 'Specific' export license for nuclear material and equipment. | Report the License Number. | Allowed | MS,GS , OI, OS | All except '60' (Passenger Hand Carried), '70' (Fixed Transport) |
| N02 | NRC 'General' Export License Report the CFR Citation Number. | Allowed | MS,GS , OI, OS | All except '60' (Passenger Hand Carried), '70' (Fixed Transport) |

###### Department of State/ Directorate of Defense Trade Control (DDTC)

When the License Code S61, S73, S85 or S94 is reported and accepted in AES, the filer is required to present the original license and proof of filing to CBP prior to export.

| Code | Description | Report | ECCN | Export | MOT | Code |
| --- | --- | --- | --- | --- | --- | --- |
| SAG | Agreements  Agreements (i.e. AG, BA, MA, RR, TA and VD) | Report ITAR DDTC Exemption Citation. | Report spaces. | Allowed | MS, GS, OI, OS | All except '70' (Fixed Transport) |
| SAU | Australia ITAR Exemptions | Report ITAR DDTC Exemption Citation | Report the Approved Community Member # | Allowed | MS, GS, OI, OS | All except '70' (Fixed Transport) |
| SCA | Canadian ITAR Exemption | Report ITAR DDTC Exemption Citation. | Report spaces. | Allowed | MS, GS, OI, OS | All except '70' (Fixed Transport) |
| SGB | United Kingdom ITAR Exemptions | Report ITAR DDTC Exemption Citation | Report the Approved Community Member # | Allowed | MS, GS, OI, OS | All except '70' (Fixed Transport) |
| S00 | License Exemption Citation | Report ITAR DDTC Exemption Citation. | Report spaces. | Allowed | MS, GS, OI, OS | All except '70' (Fixed Transport) |
| S05 | DSP-5  Permanent export of unclassified defense articles and services. | Report spaces. | Report the License Number. | Allowed | MS, GS, OI, OS | All except '70' (Fixed Transport) |
| S61 | DSP-61  Temporary import of unclassified articles. | Report spaces. | Report the License Number. | Allowed | MS, GS, OI, OS | All except '70' (Fixed Transport) |
| S73 | DSP-73  Temporary export of unclassified articles. | Report spaces. | Report the License Number. | Allowed | MS, GS, TP, OI, OS | All except '70' (Fixed Transport) |
| S85 | DSP-85  Temporary or permanent import or export of classified articles. | Report spaces. | Report the License Number. | Allowed | MS, GS, OI, OS | All except '70' (Fixed Transport) |
| S94 | DSP-94  Foreign Military Sales | Report spaces. | Report the License Number. | Allowed | MS, GS, FS, FI | All except '60' (Passenger Hand Carried), '70' (Fixed Transport) |

###### Department of Treasury/Office of Foreign Assets Control (OFAC)

|  |
|
| Code | Description | Report | ECCN | Export | MOT |
| T10 | OFAC 'Specific' License  Specific export license issued, on a case-by-case basis, by OFAC for certain export shipments that would otherwise be barred by sanctions. | Report the License Number. | Allowed | MS,GS, OI, OS | All except '60' (Passenger Hand Carried), '70' (Fixed Transport) |
| T11 | OFAC 'General' Export License  Export shipments permitted under 'General' license which conform to criteria set forth in an OFAC authorization published as a regulation (no individual clearance by OFAC) covers certain shipments that would otherwise be barred by sanctions. | Report the CFR citation or if there is no CFR citation, the Federal Register Citation if there is one, or the General License Number. | Allowed | MS,GS, OI, OS | All except '60' (Passenger Hand Carried), '70' (Fixed Transport) |
| T12 | Kimberley Process Certificate Number  The unique identifying number of the Kimberley Process Certificate (KPC) issued by the United States Kimberley Process Authority must accompany any export (re-export) of rough diamonds.  See standards, practices, and procedures of the Kimberley Process set forth in the Rough Diamond Control Regulations, 31 CFR part 592, promulgated by OFAC  (69 FR 56936 dated September 23, 2004). | Report the KPC# | EAR99 or blank only | OI, OS | All except '70' (Fixed Transport) |

###### Other Partnership Agency

| Code | Description | Report | ECCN | Export | MOT |
| --- | --- | --- | --- | --- | --- |
| OPA | Other Partnership Agency License  AES filers are required to comply with current paper documentation requirements for agencies not accommodated in AES (i.e. DEA, ATF). | Report the License Number or 'OPA'. | Allowed | All except UG, FS, FI, IW | All |

#### EEI License Types and Exemptions

Note: * AES Filing Required Regardless of Value

###### License Types

##### Section I

| License | Description |
| --- | --- |
| Commerce License | Usually articles with dual use, Military or Civilian.  1 Letter + 6 Digits Example: D123456  Note: Must have an ECCN. |
| Drug Enforcement Agency (DEA) | Exportation of controlled substances and chemicals.  5 Digits Example: 12345  or  2 Letters + 7 Digits Examples: RA1234567 or PB7654321  Note: Usually starts with R or P. |
| State Department License (SDL) | DDTC enforces the laws and regulations for defense articles, defense services and related technology such as weapons or manuals for fighter jets. The commodities are usually military related articles.  9 Digits, but must start with 05 Example: 051234567  Note: Could have "DOS" in front/behind the digits and/or an expiration date |

###### License Exemptions

##### Section II

Exempt from requiring a license, but AES Filing is required and the exemption must be stated on the provided documents.

| License Exemption | Description and Examples |
| --- | --- |
| 10 CFR 110 | Nuclear Regulatory Commission (NRC)  Key Entry: Only the digits "110" would be keyed in the License Field in OPSYS. |
| 22 CFR 120 to 130 | State Department License Exemption (SDL Exemption)  Example: 22 CFR 123.16  Key Entry: Only the digits "123.16" would be keyed in the License field in OPSYS.  See SDL Exception Matrix for AES Filing requirements. |

#### ScheduleB Unit of Measure Codes

| Code | Description |
| --- | --- |
| BBL | Barrels |
| CAR | Carat |
| CKG | Content Kilogram |
| CM2 | Square Centimeters |
| CTN | Content Ton |
| CUR | Curie |
| CYK | Clean Yield Kilogram |
| DOZ | Dozen |
| DPC | Dozen Pieces |
| DPR | Dozen Pairs |
| FBM | Fiber Meter |
| GCN | Gross Containers |
| GM | Gram |
| GRS | Gross |
| HUN | Hundred |
| KG | Kilogram |
| KM3 | 1,000 Cubic Meters |
| KTS | Kilogram Total Sugars |
| L | Liter |
| M | Meter |
| M2 | Square Meters |
| M3 | Cubic Meters |
| MC | Millicurie |
| NO | Number |
| PCS | Pieces |
| PFL | Proof Liter |
| PK | Pack |
| PRS | Pairs |
| RBA | Running Bales |
| SQ | Square |
| T | Ton |
| THS | 1,000 |
| X | No Quantity required |

#### Product Unit of Measure Codes

| Code | Description |
| --- | --- |
| BA | Barrel |
| BE | Bundle |
| BG | Bag |
| BH | Bunch |
| BOX | Box |
| BT | Bolt |
| BU | Butt |
| CI | Canister |
| CM | Centimeter |
| CON | Container |
| CR | Crate |
| CS | Case |
| CT | Carton |
| CY | Cylinder |
| DOZ | Dozen |
| EA | Each |
| EN | Envelope |
| FT | Feet |
| KG | Kilogram |
| KGS | Kilograms |
| LB | Pound |
| LBS | Pounds |
| L | Liter |
| M | Meter |
| NMB | Number |
| PA | Packet |
| PAL | Pallet |
| PC | Piece |
| PCS | Pieces |
| PF | Proof Liters |
| PKG | Package |
| PR | Pair |
| PRS | Pairs |
| RL | Roll |
| SET | Set |
| SME | Square Meters |
| SYD | Square Yards |
| TU | Tube |
| YD | Yard |
| OTH | Other |

#### Hong Kong SAR, China District Codes

The following table lists the codes UPS uses to represent Hong Kong SAR, China districts.

| District | Code |
| --- | --- |
| ABERDEEN | SD1 |
| ADMIRALTY | AD |
| AP LEI CHAU | SD2 |
| CAUSEWAY BAY | CB |
| CENTRAL | CD |
| CHA KWO LING | KT1 |
| CHAI WAN | CW1 |
| CHAK LAP KOK | CLK1 |
| CHEUNG CHAU | ISL1 |
| CHEUNG SHA WAN | CSW |
| CHOI HUNG | CH |
| CHUNG HOM KOK | SD3 |
| DAIMOND HILL | DH |
| DEEP WATER BAY | SD4 |
| DISCOVERY BAY | ISL2 |
| FANLING | FL |
| FORTRESS HILL | NP1 |
| FOTAN | ST1 |
| HAPPY VALLEY | HV |
| HO MAN TIN | HMT |
| HUNGHOM | HH |
| JORDAN | JD |
| KAM TIN | NT1 |
| KENNEDY TOWN | WD1 |
| KOWLOON BAY | KLB |
| KOWLOON CITY | KLC |
| KOWLOON TONG | KLT |
| KWAI CHUNG | KC1 |
| KWAI FONG | KC2 |
| KWAI HING | KC3 |
| KWUN TONG | KT2 |
| LAI CHI KOK | LCK1 |
| LAI KING | LCK2 |
| LAM TIN | LT3 |
| LAMMA ISLAND | ISL3 |
| LANTAU ISLAND | ISL4 |
| LOK FU | LF1 |
| MA ON SHAN | ST2 |
| MEI FOO | LCK3 |
| MIDDLE BAY | SD5 |
| MID-LEVEL | ML1 |
| MONGKOK | MK1 |
| MOUNT DAVIS | WD2 |
| NGAU TAU KOK | NTK |
| NORTH POINT | NP2 |
| PING CHAU | ISL5 |
| POK FU LAM | SD6 |
| PRINCE EDWARD | MK2 |
| QUARRY BAY | QB |
| REPULSE BAY | SD7 |
| SAI KUNG | SK |
| SAI WAN | WD3 |
| SAI WAN HO | SWH |
| SAI YING PUN | WD4 |
| SAN PO KONG | SPK |
| SHA TAU KOK | SS1 |
| SHAM SHUI PO | SSP |
| SHAM TSENG | NT2 |
| SHATIN | ST3 |
| SHAU KEI WAN | SKW |
| SHEK KIP MEI | SKM |
| SHEK KONG | NT4 |
| SHEK O | SD8 |
| SHEK TONG TSUI | WD5 |
| SHEUNG SHUI | SS2 |
| SHEUNG WAN | SW |
| SHUN LEE | SL |
| SIU LEK YUEN | ST4 |
| SIU SAI WAN | CW2 |
| SOUTH BAY | SD9 |
| SOUTHERN DISTRICT | SD10 |
| STANLEY | SD11 |
| TAI HANG | ML2 |
| TAI KOK TSUI | TKT |
| TAI LAM CHUNG | NT5 |
| TAI PO | TP |
| TAI TAM | SD12 |
| TAI WAI | ST5 |
| TAP SHEK KOK | NT6 |
| THE PEAK | ML3 |
| TIN HAU | NP3 |
| TIN SHUI WAI | NT7 |
| TIN WAN | SD13 |
| TO KWA WAN | TKW |
| TSEUNG KWAN O | TKO |
| TSIM SHA TSUI | TST1 |
| TSIM SHA TSUI EAST | TST2 |
| TSING LUNG TAU | NT8 |
| TSING YI | TY |
| TSUEN WAN | TW |
| TSZ WAN SHAN | TWS |
| TUEN MUN | NT9 |
| TUNG CHUNG | CLK2 |
| WAH FU | SD14 |
| WANCHAI | WC |
| WANG TAU HOM | LF2 |
| WESTERN DISTRICT | WD6 |
| WONG CHUK HANG | SD15 |
| WONG TAI SIN | WTS |
| YAU MA TEI | YMT |
| YAU TONG | KT3 |
| YUEN LONG | NT10 |

#### International Forms Preference Criteria

Preference criteria are required in North American Free Trade Agreement Certificate of Origin (NAFTA CO) documents. The following table lists the defined criteria and their use.

| Criteria | Meaning |
| --- | --- |
| A | The good is \"wholly obtained or produced entirely\" in the territory of one or more of the NAFTA countries or territories as referenced in Article 415.  Note: The purchase of a good in the territory does not necessarily render it\" wholly obtained or produced.\" If the good is an agricultural good, see also criterion F and Annex 703.2. (Reference: Article 401(a) and 415) |
| B | The good is produced entirely in the territory of one or more of the NAFTA countries or territories and satisfies the specific rule of origin, set out in Annex 401 that applies to its tariff classification. The rule may include a tariff classification change, regional value-content requirement, or a combination there-of.  The good must also satisfy all other applicable requirements of Chapter Four. If the good is an agricultural good, see also criterion F and Annex 703.2. (Reference: Article 401(b)) |
| C | The good is produced entirely in the territory of one or more of the NAFTA countries or territories exclusively from originating materials. Under this criterion, one or more of the materials may not fall within the definition of \"wholly produced or obtained\" as set out in Article 415.  All materials used in the production of the good must qualify as \"originating\" by meeting the rules of Article 401(a) through (d). If the good is an agricultural good, see also criterion F and Annex703.2. Reference: Article 401(c). |
| D | Goods are produced in the territory of one or more of the NAFTA countries or territories but do not meet the applicable rule of origin, set out in is an agricultural good, see also criterion F and Annex703.2.  Reference: Article 401(c). Annex 401, because certain non-originating materials do not undergo the required change in tariff classification. The goods do nonetheless meet the regional value-content requirement specified in Article 401 (d). This criterion is limited to the following two circumstances:  The good was imported into the territory of a NAFTA country or territory in an unassembled or disassembled form but was classified as an assembled good, pursuant to H.S. General Rule of Interpretation 2(a). or (2). The good incorporated one or more non- originating materials, provided for as parts under the H.S., which could not undergo a change in tariff classification because the originating materials, provided for as parts under the H.S., which could not undergo a change in tariff classification because the heading provided for both the good and its parts and was not further subdivided into subheadings, or the subheading provided for both the good and its parts and was not further subdivided. Note: This criterion does not apply to Chapters 61 through 63 of the H.S. (Reference: Article 401(d)) |
| E | Certain automatic data processing goods and their parts, specified in Annex308.1, that do not originate in the territory are considered originating upon importation into the territory of a NAFTA country or territory from the territory of another NAFTA country or territory when the most-favored- nation tariff rate of the good conforms to the rate established in Annex 308.1 and is common to all NAFTA countries or territories. (Reference: Annex 308.1) |
| F | The good is an originating agricultural good under preference criterion A, B, or C above and is not subject to a quantitative restriction in the importing NAFTA country or territory because it is a \"qualifying good\" as defined in Annex 703.2, Section A or B (please specify).  A good listed in Appendix 703.2B.7 is also exempt from quantitative restrictions and is eligible for NAFTA preferential tariff treatment if it meets the definition of \"qualifying good\" in Section A of Annex 703.2.  Note: This criterion does not apply to goods that wholly originate in Canada or the United States and are imported into either country or territory. Note: A tariff rate quota is not a quantitative restriction |

---

## Appendix 2 - Additional Reference Data

*Source: https://developer.ups.com/api/reference/shipping/appendix2?loc=en_US*

#### Language / Dialect Combinations

PreAlertNotification and UPS Access Point Notification language/dialect combinations:

| Language | Dialect |
| --- | --- |
| CES | 97 |
| DAN | 97 |
| DEU | 97 |
| ELL | 97 |
| ENG | GB |
| ENG | US |
| ENG | CA |
| ENG | CA |
| FIN | 97 |
| FRA | 97 |
| FRA | CA |
| HEB | 97 |
| HUN | 97 |
| ITA | 97 |
| NLD | 97 |
| NLD | 97 |
| NOR | 97 |
| NOR | 97 |
| POL | 97 |
| POR | 97 |
| RON | RO |
| RUS | 97 |
| SLK | 97 |
| SPA | 97 |
| SPA | PR |
| SWE | 97 |
| TUR | 97 |
| VIE | 97 |
| ZHO | TW |

#### License Exception Codes

License exception codes may be used in lieu of an export license in EEI forms.

| Values | Description |
| --- | --- |
| AGR | Established for agricultural commodities to permit exports and re-exports to Cuba that is not specifically identified on the Commerce Control List (CCL) and is classified as EAR99. |
| APR | Items for export or re-export not controlled for nuclear nonproliferation, missile technology or crime control. |
| AVS | U.S. aircraft or foreign sojourn into foreign country or territory. |
| BAG | Individual or exporting carrier's crew member's baggage. |
| CIV | National security items for civil end users. |
| CTP | Computer and parts of. |
| ENC | Encrypted software and hardware - financial institutions. |
| GBS | Export or re-export of country or territory Group B; controlled for national security reasons. |
| GFT | Gift shipments; packages to individuals, religious, charitable or education institutions, donations of basic needs. |
| GOV | Government shipments, covers shipments for U.S. government agencies, personnel or of cooperating foreign governments. |
| KMI | Encrypted software and hardware. |
| LVS | Value of shipments limited. |
| NLR | No license required. |
| RPL | Servicing and replacement of parts and equipment, one for one replacement parts service or replacement of equipment. |
| TMP | Temporary exports, export and re-export of items temporary in U.S., export and re-export of beta test software. |
| TSPA | Software or technology outside the scope of export regulations. |
| TSR | Technology and software, national security reason, country or territory Group B. |
| TSU | Technology and software shipments, of basic requirements, data supporting prospective or actual bids, offers to sell, lease or supply an item. Software update for fixing programs, mass marketed software |

#### Mail Innovations Package Detail

###### Priority and First Class Mail - Domestic

| Package Type | UOM | Weight | Endorsement Required | Type of Endorsement | Delivery Confirmation Allowed | QV Email Notification Allowed |
| --- | --- | --- | --- | --- | --- | --- |
| Priority | LBS | 1 to 70 | Required | ASR, CSR,FSR, and RSR | Allowed | Allowed |
| First Class | OZS | 1 to 15.99 | Required | ASR, CSR,FSR, and RSR | Allowed | Allowed |

###### Expedited Mail Innovations - Domestic

| Package Type | UOM | Weight | Endorsement Required | Type of Endorsement | Delivery Confirmation Allowed | QV Email Notification Allowed |
| --- | --- | --- | --- | --- | --- | --- |
| Machineables | OZS | 6 to < 16 | Required | ASR, CSR,FSR, and RSR | Allowed | Allowed |
| Irregulars | OZS | 1 to < 16 | Required | ASR, CSR,FSR, and RSR | Allowed | Allowed |
| Parcel Post | LBS | 1 to 70 | Required | ASR, CSR,FSR, and RSR | Allowed | Allowed |
| BPM Parcel | LBS | 1 to 15 | Required | ASR, CSR,FSR, and RSR | Allowed | Allowed |
| Media Mail | LBS | 1 to 70 | Required | ASR, CSR,FSR, and RSR | Allowed | Allowed |
| Standard Flats | OZS | 1 to < 16 | Prohibited | LNR | Prohibited | Allowed |
| BPM Flats | LBS | 1 to 15 | Prohibited | LNR | Prohibited | Allowed |

###### Priority and Economy Mail Innovations - International

Note*: UOM and Weight are specified at the Package Level.

| Package Type | UOM | Weight | Endorsement Required | Type of Endorsement | Delivery Confirmation Allowed | QV Email Notification Allowed |
| --- | --- | --- | --- | --- | --- | --- |
| BPM, Flats, Parcels LBS | 1 to 70 | Prohibited | LNR | Prohibited | Prohibited |
| BPM, Flats, Parcels LBS | 1 to 70 | Prohibited | LNR | Prohibited | Prohibited |

###### Table 2:

| Endorsement | Full Text | Allowed |
| --- | --- | --- |
| ASR | Address Service Requested | Allowed |
| CSR | Change Service Requested | Allowed |
| FSR | Forwarding Service Requested | Allowed |
| RSR | Return Service Requested | Allowed |
| LNR | No Service Selected | Allowed |

#### Paperless Invoice Support Countries or Territories

The following table lists those countries or territories that support paperless (electronic submission) invoices.
- NOTE: Although a country or territory may be prepared to accept Paperless Invoices, it is not guaranteed that all origins are allowed to send Paperless Invoices to such a country or territory.
Please note that not all lanes support paperless invoice. Please check Ship API responses for warning codes 120372 or 120373, if these warnings are present please include a hard copy of the invoice with your shipment.
- Please note that not all lanes support paperless invoice. Please check Ship API responses for warning codes 120372 or 120373, if these warnings are present please include a hard copy of the invoice with your shipment.
Paperless Invoice Countries or Territories are updated quarterly however guides are updated semi-annually in January and July. Changes may occur between releases of the guide.

|  |
|
| Origin_Code | Origin_Country | Dest_Code | Dest_Country |
| AE | United Arab Emirates | A2 | Azores |
| AG | Antigua and Barbuda | AD | Andorra |
| AR | Argentina | AE | United Arab Emirates |
| AT | Austria | AL | Albania |
| AU | Australia | AM | Armenia |
| AZ | Azerbaijan | AR | Argentina |
| BA | Bosnia | AS | American Samoa |
| BB | Barbados | AT | Austria |
| BD | Bangladesh | AU | Australia |
| BE | Belgium | AX | Aland Islands |
| BG | Bulgaria | AZ | Azerbaijan |
| BH | Bahrain | BA | Bosnia |
| BM | Bermuda | BB | Barbados |
| BN | Brunei | BD | Bangladesh |
| BQ | Bonaire, St. Eustatius, Saba | BE | Belgium |
| BS | Bahamas | BG | Bulgaria |
| BY | Belarus | BH | Bahrain |
| CA | Canada | BN | Brunei |
| CH | Switzerland | BT | Bhutan |
| CL | Chile | BY | Belarus |
| CN | China, People's Republic of | C3 | St. Croix |
| CO | Colombia | CA | Canada |
| CR | Costa Rica | CH | Switzerland |
| CW | Curacao | CK | Cook Islands |
| CY | Cyprus | CL | Chile |
| CZ | Czech Republic | CN | China, People's Republic of |
| DE | Germany | CO | Colombia |
| DK | Denmark | CR | Costa Rica |
| DO | Dominican Republic | CY | Cyprus |
| DZ | Algeria | CZ | Czech Republic |
| EC | Ecuador | DE | Germany |
| EE | Estonia | DK | Denmark |
| ES | Spain | DO | Dominican Republic |
| FI | Finland | DZ | Algeria |
| FJ | Fiji | EC | Ecuador |
| FR | France | EE | Estonia |
| GB | United Kingdom | EN | England |
| GE | Georgia | ES | Spain |
| GG | Guernsey | FI | Finland |
| GI | Gibraltar | FJ | Fiji |
| GR | Greece | FM | Micronesia, Federated States of |
| GT | Guatemala | FO | Faeroe Islands |
| GU | Guam | FR | France |
| HK | Hong Kong | GB | United Kingdom |
| HN | Honduras | GE | Georgia |
| HR | Croatia | GG | Guernsey |
| HT | Haiti | GH | Ghana |
| HU | Hungary | GI | Gibraltar |
| ID | Indonesia | GL | Greenland |
| IE | Ireland | GN | Guinea |
| IL | Israel | GR | Greece |
| IN | India | GT | Guatemala |
| IS | Iceland | GU | Guam |
| IT | Italy | GY | Guyana |
| JE | Jersey | HK | Hong Kong |
| JM | Jamaica | HN | Honduras |
| JP | Japan | HO | Holland |
| KE | Kenya | HR | Croatia |
| KH | Cambodia | HT | Haiti |
| KN | St. Kitts and Nevis | HU | Hungary |
| KR | Korea, South | IC | Canary Islands |
| KV | Kosovo | ID | Indonesia |
| KW | Kuwait | IE | Ireland |
| KY | Cayman Islands | IL | Israel |
| LA | Laos | IN | India |
| LC | St. Lucia | IQ | Iraq |
| LI | Liechtenstein | IS | Iceland |
| LK | Sri Lanka | IT | Italy |
| LT | Lithuania | JE | Jersey |
| LU | Luxembourg | JO | Jordan |
| LV | Latvia | JP | Japan |
| MA | Morocco | KE | Kenya |
| MC | Monaco | KH | Cambodia |
| MO | Macau | KI | Kiribati |
| MT | Malta | KN | St. Kitts and Nevis |
| MX | Mexico | KO | Kosrae |
| MY | Malaysia | KR | Korea, South |
| NB | Northern Ireland | KV | Kosovo |
| NG | Nigeria | KW | Kuwait |
| NL | Netherlands | KZ | Kazakhstan |
| NO | Norway | LA | Laos |
| NZ | New Zealand | LB | Lebanon |
| PE | Peru | LI | Liechtenstein |
| PH | Philippines | LK | Sri Lanka |
| PL | Poland | LR | Liberia |
| PR | Puerto Rico | LT | Lithuania |
| PT | Portugal | LU | Luxembourg |
| RE | Reunion | LV | Latvia |
| RO | Romania | M3 | Madeira |
| RS | Serbia | MA | Morocco |
| RU | Russia | MC | Monaco |
| SA | Saudi Arabia | MG | Madagascar |
| SE | Sweden | MH | Marshall Islands |
| SG | Singapore | MN | Mongolia |
| SI | Slovenia | MO | Macau |
| SK | Slovakia | MP | Northern Mariana Islands |
| SV | El Salvador | MT | Malta |
| SX | St. Maarten and St. Martin | MU | Mauritius |
| TH | Thailand | MV | Maldives |
| TR | Turkey | MX | Mexico |
| TT | Trinidad and Tobago | MY | Malaysia |
| TW | Taiwan | NA | Namibia |
| UA | Ukraine | NB | Northern Ireland |
| US | United States | NC | New Caledonia |
| UY | Uruguay | NF | Norfolk Island |
| VA | Vatican City State | NG | Nigeria |
| VI | U.S. Virgin Islands | NL | Netherlands |
| VI | St. Croix | NO | Norway |
| VN | Vietnam | NP | Nepal |
| ZA | South Africa | NZ | New Zealand |
|  |  | OM | Oman |
|  |  | PA | Panama |
|  |  | PE | Peru |
|  |  | PF | French Polynesia |
|  |  | PG | Papua New Guinea |
|  |  | PH | Philippines |
|  |  | PL | Poland |
|  |  | PO | Ponape |
|  |  | PR | Puerto Rico |
|  |  | PT | Portugal |
|  |  | PW | Palau |
|  |  | QA | Qatar |
|  |  | RE | Reunion |
|  |  | RO | Romania |
|  |  | RS | Serbia |
|  |  | RT | Rota |
|  |  | RU | Russia |
|  |  | SA | Saudi Arabia |
|  |  | SB | Solomon Islands |
|  |  | SE | Sweden |
|  |  | SF | Scotland |
|  |  | SG | Singapore |
|  |  | SI | Slovenia |
|  |  | SK | Slovakia |
|  |  | SM | San Marino |
|  |  | SP | Saipan |
|  |  | SR | Suriname |
|  |  | SV | El Salvador |
|  |  | TA | Tahiti |
|  |  | TH | Thailand |
|  |  | TI | Tinian |
|  |  | TL | Timor Leste |
|  |  | TO | Tonga |
|  |  | TR | Turkey |
|  |  | TU | Truk |
|  |  | TV | Tuvalu |
|  |  | TW | Taiwan |
|  |  | UA | Ukraine |
|  |  | US | United States |
|  |  | UV | St. John |
|  |  | UY | Uruguay |
|  |  | VA | Vatican City State |
|  |  | VI | U.S. Virgin Islands |
|  |  | VL | St. Thomas |
|  |  | VN | Vietnam |
|  |  | VU | Vanuatu |
|  |  | WF | Wallis and Futuna Islands |
|  |  | WL | Wales |
|  |  | WS | Samoa |
|  |  | YA | Yap |
|  |  | YT | Mayotte |
|  |  | ZA | South Africa |
|  |  | ZM | Zambia |

###### North American Free Trade Agreement (NAFTA) Supported Countries or Territories

The following table lists the NAFTA Countries or Territories that support paperless (electronic submission).
- NOTE: Although a country or territory may be prepared to accept Paperless NAFTA, it is not guaranteed that all origins are allowed to send Paperless Invoices to such a country or territory.
Mexico as an origin is not currently supported.

| Origin | Destination |
| --- | --- |
| US | CA |
| US | MX |
| CA | US |
| CA | PR |
| CA | MX |
| PR | CA |
| PR | MX |

#### Reference Number Codes

Shipments and packages may include a reference number. The type of reference number may be indicated by a reference number code.

| Code | Description |
| --- | --- |
| AJ | Accounts Receivable Customer Account |
| AT | Appropriation Number |
| BM | Bill of Lading Number |
| 9V | Collect on Delivery (COD) Number |
| ON | Dealer Order Number |
| DP | Department Number |
| 3Q | Food and Drug Administration (FDA) Product Code |
| IK | Invoice Number |
| MK | Manifest Key Number |
| MJ | Model Number |
| PM | Part Number |
| PC | Production Code |
| PO | Purchase Order Number |
| RQ | Purchase Request Number |
| RZ | Return Authorization Number |
| SA | Salesperson Number |
| SE | Serial Number |
| ST | Store Number |
| TN | Transaction Reference Number |

#### Service Codes

UPS offers a wide variety of package delivery services. The following tables list the service code values for these services; they are ordered by the origin of the shipment.
For more information on UPS services, refer to the latest UPS Rate and Service Guide available at http://www.ups.com.

- United States
- Canada
- European Union
- Mexico
- Poland
- Puerto
- Undefined Countries or Territories
- All Countries or Territories

###### United States

Shipments originating in United States

| Description | Shipping | Rating |
| --- | --- | --- |
| UPS Standard | 11 | 11 |
| UPS Worldwide Expedited | 08 | 08 |
| UPS Worldwide Express | 07 | 07 |
| UPS Worldwide Express Plus | 54 | 54 |
| UPS Worldwide Saver | 65 | 65 |
| UPS® Worldwide Economy DDP | 72 | N/A |
| UPS® Worldwide Economy DDU | 17 | N/A |

| Description | Shipping | Rating |
| --- | --- | --- |
| UPS 2nd Day Air | 02 | 02 |
| UPS 2nd Day Air A.M. | 59 | 59 |
| UPS 3 Day Select | 12 | 12 |
| UPS Expedited Mail Innovations | M4 | M4 |
| UPS First-Class Mail | M2 | M2 |
| UPS Ground | 03 | 03 |
| UPS Next Day Air | 01 | 01 |
| UPS Next Day Air Early | 14 | 14 |
| UPS Next Day Air Saver | 13 | 13 |
| UPS Priority Mail | M3 | M3 |

###### Canada

| Description | Category | Shipping | Rating |
| --- | --- | --- | --- |
| UPS Expedited | Canadian domestic shipments | 02 | 02 |
| UPS Express Saver | Canadian domestic shipments | 13 | 13 |
| UPS 3 Day Select | Shipments originating in Canada to CA and US 48 | 12 | 12 |
| UPS Access Point Economy | Canadian domestic shipments | 70 | 70 |
| UPS Express | Canadian domestic shipments | 01 | 01 |
| UPS Express Early | Canadian domestic shipments | 14 | 14 |
| UPS Express Saver | International shipments originating in Canada | 65 | 65 |
| UPS Standard | Shipments originating in Canada (Domestic and Int'l) | 11 | 11 |
| UPS Worldwide Expedited | International shipments originating in Canada | 08 | 08 |
| UPS Worldwide Express | International shipments originating in Canada | 07 | 07 |
| UPS Worldwide Express Plus | International shipments originating in Canada | 54 | 54 |
| UPS Express Early | Shipments originating in Canada to CA and US 48 | 54 | 54 |
| UPSTM Worldwide Economy DDP | International shipments originating in Canada | 72 | N/A |
| UPSTM Worldwide Economy DDU | International shipments originating in Canada | 17 | N/A |

###### European Union

| Description | Category | Shipping | Rating |
| --- | --- | --- | --- |
| UPS Access Point Economy | Shipments within the European Union | 70 | 70 |
| UPS Expedited | Shipments originating in the European Union | 08 | 08 |
| UPS Express | Shipments originating in the European Union | 07 | 07 |
| UPS Standard | Shipments originating in the European Union | 11 | 11 |
| UPS Worldwide Express Plus | Shipments originating in the European Union | 54 | 54 |
| UPS Worldwide Saver | Shipments originating in the European Union | 65 | 65 |
| UPS Express®12:00 | German Domestic Shipments | 74 | 74 |
| UPSTM Economy DDP | Shipments originating in the European Union | 72 | N/A |
| UPSTM Economy DDU | Shipments originating in the European Union | 17 | N/A |

###### Mexico

| Description | Category | Shipping | Rating |
| --- | --- | --- | --- |
| UPS Access Point Economy | Mexican Domestic Shipments | 70 | 70 |
| UPS Expedited | Shipments originating in Mexico | 08 | 08 |
| UPS Express | Shipments originating in Mexico | 07 | 07 |
| UPS Express Plus | Shipments originating in Mexico | 54 | 54 |
| UPS Standard | Shipments originating in Mexico | 11 | 11 |
| UPS Worldwide Saver | Shipments originating in Mexico | 65 | 65 |

###### Poland

| Description | Category | Shipping | Rating |
| --- | --- | --- | --- |
| UPS Access Point Economy | Polish Domestic Shipments | 70 | 70 |
| UPS Expedited | International Shipments originating in Poland | 08 | 08 |
| UPS Express | Shipments originating in Poland | 07 | 07 |
| UPS Express Plus | Shipments originating in Poland | 54 | 54 |
| UPS Express Saver | Shipments originating in Poland | 65 | 65 |
| UPS Standard | Shipments originating in Poland | 11 | 11 |
| UPS Today Dedicated Courier | Polish Domestic Shipments | 83 | 83 |
| UPS Today Express | Polish Domestic Shipments | 85 | 85 |
| UPS Today Express Saver | Polish Domestic Shipments | 86 | 86 |
| UPS Today Standard | Polish Domestic Shipments | 82 | 82 |
| UPSTM Economy DDP | International shipments originating in Poland | 72 | N/A |
| UPSTM Economy DDU | International shipments originating in Poland | 17 | N/A |

###### Puerto Rico

Shipments originating in Puerto Rico

| Description | Shipping | Rating |
| --- | --- | --- |
| UPS 2nd Day Air | 02 | 02 |
| UPS Ground | 03 | 03 |
| UPS Next Day Air | 01 | 01 |
| UPS Next Day Air Early | 14 | 14 |
| UPS Worldwide Expedited | 08 | 08 |
| UPS Worldwide Express | 07 | 07 |
| UPS Worldwide Express Plus | 54 | 54 |
| UPS Worldwide Saver | 65 | 65 |

###### Undefined Countries or Territories

Use for all countries or territories other than United States, Canada, European Union, Mexico, Poland, and Puerto Rico

| Description | Shipping | Rating |
| --- | --- | --- |
| UPS Worldwide Express | 07 | 07 |
| UPS Standard | 11 | 11 |
| UPS Worldwide Expedited | 08 | 08 |
| UPS Worldwide Express Plus | 54 | 54 |
| UPS Worldwide Saver | 65 | 65 |

###### All Countries or Territories

Shipments originating in any country or territory

| Description | Shipping | Rating |
| --- | --- | --- |
| UPS Worldwide Express Freight | 96 | 96 |
| UPS Priority Mail Innovations | M5 | M5 |
| UPS Economy Mail Innovations | M6 | M6 |
| UPS Worldwide Express Freight Mid-day | 71 | 71 |

#### Tax Type Values / Abbreviations

| • ALV | • GST | • MOMS | • PVN |
| --- | --- | --- | --- |
| • BTW | • HST | • MVA | • QST |
| • DDS | • IVA | • MWST | • TVA |
| • DDV | • IVA1 | • PDV | • VAT |
| • DPH | • IVA2 | • PST | • VSK |
| • FP | • IVA3 | • PVM |  |

#### Third Party/Freight Collect Supported Countries or Territories

Shipping API can return Third Party/Freight Collect negotiated rates in response for accounts from these countries or territories:

| Country or Territory Name | Country or Territory Code |
| --- | --- |
| Australia | AU |
| Austria | AT |
| Belgium | BE |
| Canada | CA |
| China Mainland | CN |
| Denmark | DK |
| Dominican Republic | DO |
| Finland | FI |
| France | FR |
| Germany | DE |
| Hong Kong SAR,China | HK |
| India | IN |
| Indonesia | ID |
| Italy | IT |
| Japan | JP |
| Macau SAR, China | MO |
| Malaysia | MY |
| Mexico | MX |
| Netherlands | NL |
| Norway | NO |
| Philippines | PH |
| Poland | PL |
| Portugal | PT |
| Puerto Rico | PR |
| Republic of Ireland | IE |
| Singapore | SG |
| Slovakia | SK |
| Slovenia | SI |
| South Korea | KR |
| Spain | ES |
| Sweden | SE |
| Switzerland | CH |
| Taiwan, China | TW |
| Thailand | TH |
| United Kingdom | GB |
| United States | US |
| US Virgin Islands | VI |
| Vietnam | VN |

#### SubVersion Details

UPS uses sub version strategy to give back new elements in the response when there is no functionality change in the request or to enhance the existing functionality. In order to ensure that UPS does not break the client's application, UPS give back the new elements in response or acknowledges enhanced functionality for existing request elements only when the subversion is specified in the request.

###### Shipment API

For ShipmentRequest:
UPS acknowledges HazMat aka Dangerous Goods functionality/elements in Ship API request only if SubVersion value is greater than or equal to 1701 is present.
For ShipmentResponse:

| Request Element | Valid Values |
| --- | --- |
| /ShipmentRequest/Request/SubVersion | 1601, 1607, 1701, 1707, 1807, 2108, 2205 |

| ShipmentRequest/Request/SubVersion | New Request elements acknowledged in ShipmentRequest |
| --- | --- |
| Greater than or equal to 1701 | /Shipment/Package/PackageServiceOptions/HazMat  /Shipment/Package/PackageServiceOptions/HazMat/PackagingTypeQuantity  /Shipment/Package/PackageServiceOptions/HazMat/RecordIdentifier1  /Shipment/Package/PackageServiceOptions/HazMat/RecordIdentifier2  /Shipment/Package/PackageServiceOptions/HazMat/RecordIdentifier3  /Shipment/Package/PackageServiceOptions/HazMat/SubRiskClass  /Shipment/Package/PackageServiceOptions/HazMat/aDRItemNumber  /Shipment/Package/PackageServiceOptions/HazMat/aDRPackingGroupLetter  /Shipment/Package/PackageServiceOptions/HazMat/TechnicalName  /Shipment/Package/PackageServiceOptions/HazMat/HazardLabelRequired  /Shipment/Package/PackageServiceOptions/HazMat/ClassDivisionNumber  /Shipment/Package/PackageServiceOptions/HazMat/ReferenceNumber  /Shipment/Package/PackageServiceOptions/HazMat/Quantity  /Shipment/Package/PackageServiceOptions/HazMat/UOM  /Shipment/Package/PackageServiceOptions/HazMat/PackagingType  /Shipment/Package/PackageServiceOptions/HazMat/IDNumber  /Shipment/Package/PackageServiceOptions/HazMat/ProperShippingName  /Shipment/Package/PackageServiceOptions/HazMat/AdditionalDescription  /Shipment/Package/PackageServiceOptions/HazMat/PackagingGroupType  /Shipment/Package/PackageServiceOptions/HazMat/PackagingInstructionCode  /Shipment/Package/PackageServiceOptions/HazMat/EmergencyPhone  /Shipment/Package/PackageServiceOptions/HazMat/EmergencyContact  /Shipment/Package/PackageServiceOptions/HazMat/ReportableQuantity  /Shipment/Package/PackageServiceOptions/HazMat/RegulationSet  /Shipment/Package/PackageServiceOptions/HazMat/TransportationMode  /Shipment/Package/PackageServiceOptions/HazMat/ChemicalRecordIdentifier  /Shipment/Package/PackageServiceOptions/PackageIdentifier  /Shipment/Package/HazMatPackageInformation  /Shipment/Package/HazMatPackageInformation/AllPackedInOneIndicator  /Shipment/Package/HazMatPackageInformation/OverPackedIndicator  /Shipment/Package/HazMatPackageInformation/QValue |
| Greater than or equal to 2108 | /Shipment/Package/OversizeIndicator  /Shipment/Package/MinimumBillableWeightIndicator |
| Greater than or equal to 2205 | /ShipmentResults/PackageResults/RateModifier /ShipmentResults/PackageResults/RateModifier/ModifierType /ShipmentResults/PackageResults/RateModifier/ModifierDesc /ShipmentResults/PackageResults/RateModifier/Amount |

| SubVersion Request Values | New Response Containers/Elements For ShipmentResponse |
| --- | --- |
| Greater than or equal to 1601 | /ShipmentResults/ShipmentCharges/ItemizedCharges  /ShipmentResults/ShipmentCharges/ItemizedCharges/Code  /ShipmentResults/ShipmentCharges/ItemizedCharges/Description  /ShipmentResults/ShipmentCharges/ItemizedCharges/CurrencyCode  /ShipmentResults/ShipmentCharges/ItemizedCharges/MonetaryValue  /ShipmentResults/ShipmentCharges/ItemizedCharges/SubType |
| /ShipmentResults/NegotiatedRateCharges/ItemizedCharges  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/Code  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/Description  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/CurrencyCode  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/MonetaryValue  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/SubType |
| Greater than or equal to 1607 | /ShipmentResults/PackageResults/ItemizedCharges  /ShipmentResults/PackageResults/ItemizedCharges/Code  /ShipmentResults/PackageResults/ItemizedCharges/Description  /ShipmentResults/PackageResults/ItemizedCharges/CurrencyCode  /ShipmentResults/PackageResults/ItemizedCharges/MonetaryValue  /ShipmentResults/PackageResults/ItemizedCharges/SubType |
| /ShipmentResults/PackageResults/NegotiatedCharges  /ShipmentResults/PackageResults/NegotiatedCharges/ItemizedCharges  /ShipmentResults/PackageResults/NegotiatedCharges/ItemizedCharges/Code  /ShipmentResults/PackageResults/NegotiatedCharges/ItemizedCharges/Description  /ShipmentResults/PackageResults/NegotiatedCharges/ItemizedCharges/CurrencyCode  /ShipmentResults/PackageResults/NegotiatedCharges/ItemizedCharges/MonetaryValue  /ShipmentResults/PackageResults/NegotiatedCharges/ItemizedCharges/SubType |
| Greater than or equal to 1701 | /ShipmentResults/ShipmentCharges/BaseServiceCharge  /ShipmentResults/ShipmentCharges/BaseServiceCharge/CurrencyCode  /ShipmentResults/ShipmentCharges/BaseServiceCharge/MonetaryValue |
| /ShipmentResults/PackageResults/BaseServiceCharge  /ShipmentResults/PackageResults/BaseServiceCharge/CurrencyCode  /ShipmentResults/PackageResults/BaseServiceCharge/MonetaryValue |
| Greater than or equal to 1707 for Electronic Return Label Return Service or Electronic Import Control Label shipments | /ShipmentResults/PackageResults/ShippingLabel  /ShipmentResults/PackageResults/ShippingLabel/ImageFormat  /ShipmentResults/PackageResults/ShippingLabel/ImageFormat/Code  /ShipmentResults/PackageResults/ShippingLabel/ImageFormat/Description  /ShipmentResults/PackageResults/ShippingLabel/GraphicImage  /ShipmentResults/PackageResults/ShippingLabel/InternationalSignatureGraphicImage  /ShipmentResults/PackageResults/ShippingLabel/HTMLImage  /ShipmentResults/PackageResults/ShippingLabel/PDF417 |
| /ShipmentResults/PackageResults/Form  /ShipmentResults/PackageResults/Form/Code  /ShipmentResults/PackageResults/Form/Description  /ShipmentResults/PackageResults/Form/Image  /ShipmentResults/PackageResults/Form/Image/ImageFormat  /ShipmentResults/PackageResults/Form/Image/ImageFormat/Code  /ShipmentResults/PackageResults/Form/Image/ImageFormat/Description  /ShipmentResults/PackageResults/Form/Image/GraphicImage  /ShipmentResults/PackageResults/Form/FormGroupId  /ShipmentResults/PackageResults/Form/FormGroupIdName |
| /ShipmentResults/PackageResults/ShippingReceipt  /ShipmentResults/PackageResults/ShippingReceipt/ImageFormat  /ShipmentResults/PackageResults/ShippingReceipt/ImageFormat/Code  /ShipmentResults/PackageResults/ShippingReceipt/ImageFormat/Description  /ShipmentResults/PackageResults/ShippingReceipt/GraphicImage  /ShipmentResults/Form  /ShipmentResults/Form/Code  /ShipmentResults/Form/Description  /ShipmentResults/Form/Image  /ShipmentResults/Form/Image/ImageFormat  /ShipmentResults/Form/Image/ImageFormat/Code  /ShipmentResults/Form/Image/ImageFormat/Description  /ShipmentResults/Form/Image/GraphicImage  /ShipmentResults/Form/FormGroupId  /ShipmentResults/Form/FormGroupIdName |
| Greater than or equal to 1707 for UPS Worldwide Express Freight service and UPS Worldwide Express Freight Mid-day service with Dry Ice or Oversize Pallet | /ShipmentResults/PackageResults/Accessorial  /ShipmentResults/PackageResults/Accessorial/Code  /ShipmentResults/PackageResults/Accessorial/Description |
| Greater than or equal to 1801 | /Fault/detail/Errors/ElementLevelInformation  /Fault/detail/Errors/ElementLevelInformation/Level  /Fault/detail/Errors/ElementLevelInformation/ElementIdentifier  /Fault/detail/Errors/ElementLevelInformation/ElementIdentifier/Code  /Fault/detail/Errors/ElementLevelInformation/ElementIdentifier/Value |

###### Ship Confirm API

For ShipConfirmRequest:
UPS acknowledges HazMat aka Dangerous Goods functionality/elements in Ship API request only if SubVersion value is greater than or equal to 1701 is present.
For ShipConfirmResponse:

| Request Element | Valid Values |
| --- | --- |
| /ShipConfirmRequest/Request/SubVersion | 1601, 1607, 1701, 1707,1807 |

| /ShipConfirmRequest/Request/SubVersion | New Request elements acknowledged in ShipConfirmRequest |
| --- | --- |
| Greater than or equal to 1701 | /Shipment/Package/PackageServiceOptions/HazMat  /Shipment/Package/PackageServiceOptions/HazMat/PackagingTypeQuantity  /Shipment/Package/PackageServiceOptions/HazMat/RecordIdentifier1  /Shipment/Package/PackageServiceOptions/HazMat/RecordIdentifier2  /Shipment/Package/PackageServiceOptions/HazMat/RecordIdentifier3  /Shipment/Package/PackageServiceOptions/HazMat/SubRiskClass  /Shipment/Package/PackageServiceOptions/HazMat/aDRItemNumber  /Shipment/Package/PackageServiceOptions/HazMat/aDRPackingGroupLetter  /Shipment/Package/PackageServiceOptions/HazMat/TechnicalName  /Shipment/Package/PackageServiceOptions/HazMat/HazardLabelRequired  /Shipment/Package/PackageServiceOptions/HazMat/ClassDivisionNumber  /Shipment/Package/PackageServiceOptions/HazMat/ReferenceNumber  /Shipment/Package/PackageServiceOptions/HazMat/Quantity  /Shipment/Package/PackageServiceOptions/HazMat/UOM  /Shipment/Package/PackageServiceOptions/HazMat/PackagingType  /Shipment/Package/PackageServiceOptions/HazMat/IDNumber  /Shipment/Package/PackageServiceOptions/HazMat/ProperShippingName  /Shipment/Package/PackageServiceOptions/HazMat/AdditionalDescription  /Shipment/Package/PackageServiceOptions/HazMat/PackagingGroupType  /Shipment/Package/PackageServiceOptions/HazMat/PackagingInstructionCode  /Shipment/Package/PackageServiceOptions/HazMat/EmergencyPhone  /Shipment/Package/PackageServiceOptions/HazMat/EmergencyContact  /Shipment/Package/PackageServiceOptions/HazMat/ReportableQuantity  /Shipment/Package/PackageServiceOptions/HazMat/RegulationSet  /Shipment/Package/PackageServiceOptions/HazMat/TransportationMode  /Shipment/Package/PackageServiceOptions/HazMat/ChemicalRecordIdentifier  /Shipment/Package/PackageServiceOptions/PackageIdentifier  /Shipment/Package/HazMatPackageInformation  /Shipment/Package/HazMatPackageInformation/AllPackedInOneIndicator  /Shipment/Package/HazMatPackageInformation/OverPackedIndicator  /Shipment/Package/HazMatPackageInformation/QValue |

| SubVersion Request Values | New Response Containers/Elements For ShipConfirmResponse |
| --- | --- |
| Greater than or equal to 1601 | /ShipmentResults/ShipmentCharges/ItemizedCharges  /ShipmentResults/ShipmentCharges/ItemizedCharges/Code  /ShipmentResults/ShipmentCharges/ItemizedCharges/Description  /ShipmentResults/ShipmentCharges/ItemizedCharges/CurrencyCode  /ShipmentResults/ShipmentCharges/ItemizedCharges/MonetaryValue  /ShipmentResults/ShipmentCharges/ItemizedCharges/SubType |
| /ShipmentResults/NegotiatedRateCharges/ItemizedCharges  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/Code  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/Description  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/CurrencyCode  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/MonetaryValue  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/SubType |
| Greater than or equal to 1701 | /ShipmentResults/ShipmentCharges/BaseServiceCharge  /ShipmentResults/ShipmentCharges/BaseServiceCharge/CurrencyCode  /ShipmentResults/ShipmentCharges/BaseServiceCharge/MonetaryValue |
| Greater than or equal to 1801 | /Fault/detail/Errors/ElementLevelInformation  /Fault/detail/Errors/ElementLevelInformation/Level  /Fault/detail/Errors/ElementLevelInformation/ElementIdentifier  /Fault/detail/Errors/ElementLevelInformation/ElementIdentifier/Code  /Fault/detail/Errors/ElementLevelInformation/ElementIdentifier/Value |

###### Ship Accept API

For ShipAcceptRequest:
For ShipAcceptResponse:

| Request Element | Valid Values |
| --- | --- |
| /ShipAcceptRequest/Request/SubVersion | 1601, 1607, 1701, 1707,1807 |

| SubVersion Request Values | New Response Containers/Elements For ShipAcceptResponse |
| --- | --- |
| Greater than or equal to 1601 | /ShipmentResults/ShipmentCharges/ItemizedCharges  /ShipmentResults/ShipmentCharges/ItemizedCharges/Code  /ShipmentResults/ShipmentCharges/ItemizedCharges/Description  /ShipmentResults/ShipmentCharges/ItemizedCharges/CurrencyCode  /ShipmentResults/ShipmentCharges/ItemizedCharges/MonetaryValue  /ShipmentResults/ShipmentCharges/ItemizedCharges/SubType |
| /ShipmentResults/NegotiatedRateCharges/ItemizedCharges  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/Code  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/Description  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/CurrencyCode  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/MonetaryValue  /ShipmentResults/NegotiatedRateCharges/ItemizedCharges/SubType |
| Greater than or equal to 1607 | /ShipmentResults/PackageResults/ItemizedCharges  /ShipmentResults/PackageResults/ItemizedCharges/Code  /ShipmentResults/PackageResults/ItemizedCharges/Description  /ShipmentResults/PackageResults/ItemizedCharges/CurrencyCode  /ShipmentResults/PackageResults/ItemizedCharges/MonetaryValue  /ShipmentResults/PackageResults/ItemizedCharges/SubType | /ShipmentResults/PackageResults/NegotiatedCharges  /ShipmentResults/PackageResults/NegotiatedCharges/ItemizedCharges  /ShipmentResults/PackageResults/NegotiatedCharges/ItemizedCharges/Code  /ShipmentResults/PackageResults/NegotiatedCharges/ItemizedCharges/Description  /ShipmentResults/PackageResults/NegotiatedCharges/ItemizedCharges/CurrencyCode  /ShipmentResults/PackageResults/NegotiatedCharges/ItemizedCharges/MonetaryValue  /ShipmentResults/PackageResults/NegotiatedCharges/ItemizedCharges/SubType |
| Greater than or equal to 1701 | /ShipmentResults/ShipmentCharges/BaseServiceCharge  /ShipmentResults/ShipmentCharges/BaseServiceCharge/CurrencyCode  /ShipmentResults/ShipmentCharges/BaseServiceCharge/MonetaryValue | /ShipmentResults/PackageResults/BaseServiceCharge  /ShipmentResults/PackageResults/BaseServiceCharge/CurrencyCode  /ShipmentResults/PackageResults/BaseServiceCharge/MonetaryValue |
| Greater than or equal to 1707 for Electronic Return Label Return Service or Electronic Import Control Label shipments | /Shipment/PackageResults/ShippingLabel  /Shipment/PackageResults/ShippingLabel/ImageFormat  /Shipment/PackageResults/ShippingLabel/ImageFormat/Code  /Shipment/PackageResults/ShippingLabel/ImageFormat/Description  /Shipment/PackageResults/ShippingLabel/GraphicImage  /Shipment/PackageResults/ShippingLabel/InternationalSignatureGraphicImage  /Shipment/PackageResults/ShippingLabel/HTMLImage  /Shipment/PackageResults/ShippingLabel/PDF417  /Shipment/PackageResults/Form  /Shipment/PackageResults/Form/Code  /Shipment/PackageResults/Form/Description  /Shipment/PackageResults/Form/Image  /Shipment/PackageResults/Form/Image/ImageFormat  /Shipment/PackageResults/Form/Image/ImageFormat/Code  /Shipment/PackageResults/Form/Image/ImageFormat/Description  /Shipment/PackageResults/Form/Image/GraphicImage  /Shipment/PackageResults/Form/FormGroupId  /Shipment/PackageResults/Form/FormGroupIdName |
| /ShipmentResults/PackageResults/ShippingReceipt  /ShipmentResults/PackageResults/ShippingReceipt/ImageFormat  /ShipmentResults/PackageResults/ShippingReceipt/ImageFormat/Code  /ShipmentResults/PackageResults/ShippingReceipt/ImageFormat/Description  /ShipmentResults/PackageResults/ShippingReceipt/GraphicImage |
| /ShipmentResults/Form  /ShipmentResults/Form/Code  /ShipmentResults/Form/Description  /ShipmentResults/Form/Image  /ShipmentResults/Form/Image/ImageFormat  /ShipmentResults/Form/Image/ImageFormat/Code  /ShipmentResults/Form/Image/ImageFormat/Description  /ShipmentResults/Form/Image/GraphicImage  /ShipmentResults/Form/FormGroupId  /ShipmentResults/Form/FormGroupIdName |
| Greater than or equal to 1707 for UPS Worldwide Express Freight service and UPS Worldwide Express Freight Mid-day service with Dry Ice or Oversize Pallet | /ShipmentResults/PackageResults/Accessorial  /ShipmentResults/PackageResults/Accessorial/Code  /ShipmentResults/PackageResults/Accessorial/Description |
| Greater than or equal to 1801 | /Fault/detail/Errors/ElementLevelInformation  /Fault/detail/Errors/ElementLevelInformation/Level  /Fault/detail/Errors/ElementLevelInformation/ElementIdentifier  /Fault/detail/Errors/ElementLevelInformation/ElementIdentifier/Code  /Fault/detail/Errors/ElementLevelInformation/ElementIdentifier/Value |

###### Label Recovery API

For LabelRecoveryRequest:
For LabelRecoveryResponse:

| Request Element | Valid Values |
| --- | --- |
| /LabelRecoveryRequest/Request/SubVersion | 1701, 1707 |

| Request SubVersion Values | New Response Containers/Elements For LabelRecoveryResponse |
| --- | --- |
| Greater than or equal to 1701 | /LabelRecoveryResponse/CODTurnInPage  /LabelRecoveryResponse/CODTurnInPage/Image  /LabelRecoveryResponse/CODTurnInPage/Image/ImageFormat  /LabelRecoveryResponse/CODTurnInPage/Image/ImageFormat/Code  /LabelRecoveryResponse/CODTurnInPage/Image/ImageFormat/Description  /LabelRecoveryResponse/CODTurnInPage/Image/GraphicImage |
| Greater than or equal to 1707 for Electronic Return Label Return Service or Electronic Import Control Label shipments | /LabelRecoveryResponse/Form  /LabelRecoveryResponse/Form/Image  /LabelRecoveryResponse/Form/Image/ImageFormat  /LabelRecoveryResponse/Form/Image/ImageFormat/Code  /LabelRecoveryResponse/Form/Image/ImageFormat/Description  /LabelRecoveryResponse/Form/Image/GraphicImage  /ShipmentResults/PackageResults/LabelImage/HTMLImage  /ShipmentResults/PackageResults/LabelImage/PDF417 |
| /LabelRecoveryResponse/HighValueReport  /LabelRecoveryResponse/HighValueReport/Image  /LabelRecoveryResponse/HighValueReport/Image/ImageFormat  /LabelRecoveryResponse/HighValueReport/Image/ImageFormat/Code  /LabelRecoveryResponse/HighValueReport/Image/ImageFormat/Description  /LabelRecoveryResponse/HighValueReport/Image/GraphicImage |
| /LabelRecoveryResponse/LabelResults/Form  /LabelRecoveryResponse/LabelResults/Form/Image  /LabelRecoveryResponse/LabelResults/Form/Image/ImageFormat  /LabelRecoveryResponse/LabelResults/Form/Image/ImageFormat/Code  /LabelRecoveryResponse/LabelResults/Form/Image/ImageFormat/Description  /LabelRecoveryResponse/LabelResults/Form/Image/GraphicImage |

#### Supported Locales

| Locale | Language |
| --- | --- |
| bg_BG | Bulgarian |
| cs_CZ | Czech |
| da_DK | Danish |
| de_DE | German |
| el_GR | Greek |
| en_CA | Canada English |
| en_GB | Queen's English |
| en_US | US English |
| es_AR | Argentina Spanish |
| es_ES | Spain Spanish |
| es_MX | Mexico Spanish |
| es_PR | Puerto Rico Spanish |
| et_EE | Estonian |
| fi_FI | Finnish |
| fr_CA | Canada French |
| fr_FR | France French |
| he_IL | Hebrew |
| hu_HU | Hungarian |
| it_IT | Italian |
| ja_JP | Japanese |
| ko_KR | Korean |
| lt_LT | Lithuanian |
| lv_LV | Latvian |
| nl_NL | Dutch |
| no_NO | Norwegian |
| pl_PL | Polish |
| pt_BR | Brazil Portuguese |
| pt_PT | Portugal Portuguese |
| ro_RO | Romanian |
| ru_RU | Russian |
| sk_SK | Slovakian |
| sv_SE | Swedish |
| th_TH | Thai |
| tr_TR | Turkish |
| vi_VN | Vietnamese |
| zh_CN | China Chinese |
| zh_HK | Hong Kong Chinese |
| zh_TW | Taiwan Chinese |

#### Worldwide Economy

###### DDP Size and Weight Restrictions Table

Child packages must adhere to the following size and weight measurements:

| Measurement | Limitation |
| --- | --- |
| Length | 108 IN (274 CM) |
| Size (Length + Girth)  (Girth = 2*Width + 2*Height) | 165 IN (419 CM) |
| Weight (for most countries) | 150 LB (70 KG) |

###### DDU Size and Weight Restrictions Table

Child packages must adhere to the following size and weight measurements:

| Measurement | Limitation |
| --- | --- |
| Length | 60 IN (152 CM) |
| Size (Length + Girth)  (Girth = 2*Width + 2*Height) | 108 IN (274 CM) |
| Weight (for most countries) | 70.00 LB (31.00 KG) |

#### UPS Premier - Special Handling Instructions Codes

| Instruction Code | Handling Instruction (Executed Upon Exception with Delay) | Exclusivity with Other Codes | Silver | Gold | Platinum |
| --- | --- | --- | --- | --- | --- |
| 001 | No Special Handling Required | Cannot be selected with any other code | 1 | 1 | 1 |
| 002 | Controlled Room Temperature (CRT) - Do Not Refrigerate, Maintain Temp Range = 15 - 25 C) | Mutually Exclusive (within 02 through 06) | 1 | 1 | 1 |
| 003 | Refrigerate (Temp Range = 2-8 C) | 1 | 1 | 1 |
| 004 | Frozen (Freezer) ( Temp Range <-20 C) | 1 | 1 | 1 |
| 005 | Dry Ice Replenish (Temp Range <-80 C) | 1 | 1 | 1 |
| 006 | Cryo - Liquid-Nitrogen dry-vapor (Temp Range < -150 C) - do not open liquid nitrogen tank | 1 | 1 | 1 |
| 007 | Return to Shipper |  | 1 | 1 | 1 |
| 008 | Expedite To Receiver - All Modes Up to and Including Extraction to Courier | Mutually Exclusive (within 08 through 10) |  | 1 | 1 |
| 009 | Expedite To Receiver - UPS Air Network Only (Next Flight) | 1 | 1 | 1 |
| 010 | Expedite To Receiver - Ground Courier only (No Air) |  | 1 | 1 |
| 011 | Hold for Instruction |  | 1 | 1 | 1 |
| 012 | Hold for Will Call | 1 | 1 | 1 |
| 014 | Contact UPS Premier Control Tower | 1 | 1 | 1 |
| 015 | Upgrade to Weekend Delivery (if not delivered Friday) | 1 | 1 | 1 |

