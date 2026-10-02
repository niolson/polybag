# FedEx API Reference Guide

> **Source:** https://developer.fedex.com/api/en-us/guides/api-reference.html  
> **Purpose:** Reference data for integrating with the FedEx API (service types, package types, codes, enumerations, etc.)

---

## Table of Contents

  - [Service Types](#service-types)
  - [U.S. Region Service List](#us-region-service-list)
  - [Canada Region Service List](#canada-region-service-list)
  - [LAC Region Service List](#lac-region-service-list)
  - [APAC Region Service List](#apac-region-service-list)
  - [MEISA Region Service List](#meisa-region-service-list)
  - [EU Region International Service List](#eu-region-international-service-list)
  - [EU Region Domestic Service List](#eu-region-domestic-service-list)
  - [Europe New Domestic Services Portfolio](#europe-new-domestic-services-portfolio)
  - [Package Types](#package-types)
  - [Shipment Level Special Service Types](#shipment-level-special-service-types)
  - [Freight Level Special Service Types](#freight-level-special-service-types)
  - [Package Level Special Service Types](#package-level-special-service-types)
  - [Sub Package Types](#sub-package-types)
  - [Country Codes](#country-codes)
  - [Currency Codes](#currency-codes)
  - [Pickup Types](#pickup-types)
  - [Notification Event Types](#notification-event-types)
  - [Locales](#locales)
  - [Tracking Status Codes](#tracking-status-codes)
  - [Monitoring and Intervention Options](#monitoring-and-intervention-options)
  - [Healthcare Identifier Options](#healthcare-identifier-options)
  - [Status Code and Statuses for Webhook Tracking Events](#status-code-and-statuses-for-webhook-tracking-events)
  - [Content Types](#content-types)
  - [Customer Reference Types](#customer-reference-types)
  - [Freight LTL Direct Special Service Categorization](#freight-ltl-direct-special-service-categorization)
  - [Freight LTL Direct Service Options and Constraints](#freight-ltl-direct-service-options-and-constraints)
  - [Label Stock Types](#label-stock-types)
  - [Retrieve ITN Endpoint Response Status and Description](#retrieve-itn-endpoint-response-status-and-description)
  - [Minimum Customs Value Countries or Territories for Document Shipments](#minimum-customs-value-countries-or-territories-for-document-shipments)
  - [Ground® Economy Hub IDs](#ground-economy-hub-ids)
  - [Customs-Approved Document Descriptions](#customsapproved-document-descriptions)
  - [Export Documents](#export-documents)
  - [Export documents for Switzerland](#export-documents-for-switzerland)
  - [Surcharges](#surcharges)
  - [Discounts](#discounts)
  - [Canada Province Codes](#canada-province-codes)
  - [India State Codes](#india-state-codes)
  - [Mexico State Codes](#mexico-state-codes)
  - [U.S. State Codes](#us-state-codes)
  - [United Arab Emirates (UAE) State Codes](#united-arab-emirates-uae-state-codes)
  - [FedEx Express International Countries/Territories Served (Ship to and from)](#fedex-express-international-countriesterritories-served-ship-to-and-from)
  - [Countries/Territories Not Served by FedEx until further notice](#countriesterritories-not-served-by-fedex-until-further-notice)
  - [Mock Tracking Numbers for FedEx Express and FedEx Ground](#mock-tracking-numbers-for-fedex-express-and-fedex-ground)
  - [Mock Tracking Numbers for FedEx Ground® Economy (Formerly known as FedEx SmartPost®)](#mock-tracking-numbers-for-fedex-ground-economy-formerly-known-as-fedex-smartpost)
  - [Postal Aware Countries](#postal-aware-countries)
  - [Track Special Handling Types](#track-special-handling-types)
  - [Harmonized System Code Unit of Measure - Table 1](#harmonized-system-code-unit-of-measure-table-1)
  - [Harmonized System Code Unit of Measure - Table 2](#harmonized-system-code-unit-of-measure-table-2)
  - [FedEx Express Special Handling Codes](#fedex-express-special-handling-codes)
  - [Variable Handling Fees](#variable-handling-fees)
  - [Vague Commodity Descriptions](#vague-commodity-descriptions)
  - [Shipping Documents](#shipping-documents)
  - [Shipment Document Type](#shipment-document-type)
  - [Address Attributes](#address-attributes)

---

### Service Types

### U.S. Region Service List

| SERVICE TYPE | ENUMERATION |
| --- | --- |
| FedEx International Priority® Express | FEDEX_INTERNATIONAL_PRIORITY_EXPRESS |
| FedEx International First® | INTERNATIONAL_FIRST |
| FedEx International Priority® | FEDEX_INTERNATIONAL_PRIORITY |
| FedEx LTL Freight Priority | FEDEX_FREIGHT_PRIORITY |
| FedEx LTL Freight Economy | FEDEX_FREIGHT_ECONOMY |
| FedEx International Economy® | INTERNATIONAL_ECONOMY |
| FedEx International Ground® and FedEx Domestic Ground® | FEDEX_GROUND |
| FedEx First Overnight® | FIRST_OVERNIGHT |
| FedEx First Overnight® Freight | FEDEX_FIRST_FREIGHT |
| FedEx 1Day® Freight (Hawaii service is to and from the island of Oahu only) | FEDEX_1_DAY_FREIGHT |
| FedEx 2Day® Freight (Hawaii service is to and from the island of Oahu only) | FEDEX_2_DAY_FREIGHT |
| FedEx 3Day® Freight (Except Alaska and Hawaii) | FEDEX_3_DAY_FREIGHT |
| FedEx International Priority® Freight | INTERNATIONAL_PRIORITY_FREIGHT |
| FedEx International Economy® Freight | INTERNATIONAL_ECONOMY_FREIGHT |
| FedEx International Connect Plus® | FEDEX_INTERNATIONAL_CONNECT_PLUS |
| FedEx® International Deferred Freight | FEDEX_INTERNATIONAL_DEFERRED_FREIGHT |
| FedEx International Priority DirectDistribution® | INTERNATIONAL_PRIORITY_DISTRIBUTION |
| FedEx International Priority DirectDistribution® Freight | INTERNATIONAL_DISTRIBUTION_FREIGHT |
| International Ground® Distribution (IGD) | INTL_GROUND_DISTRIBUTION |
| FedEx Home Delivery® | GROUND_HOME_DELIVERY |
| FedEx Ground® Economy (Formerly known as FedEx SmartPost®) | SMART_POST |
| FedEx Priority Overnight® | PRIORITY_OVERNIGHT |
| FedEx Standard Overnight® (Hawaii outbound only) | STANDARD_OVERNIGHT |
| FedEx 2Day® (Except Intra-Hawaii) | FEDEX_2_DAY |
| FedEx 2Day® AM (Hawaii outbound only) | FEDEX_2_DAY_AM |
| FedEx Express Saver® (Except Alaska and Hawaii) | FEDEX_EXPRESS_SAVER |
| FedEx SameDay® | SAME_DAY |
| FedEx SameDay® City (Selected U.S. Metro Areas) | SAME_DAY_CITY |

### Canada Region Service List

| SERVICE TYPE | ENUMERATION |
| --- | --- |
| FedEx First Overnight® | FIRST_OVERNIGHT |
| FedEx Priority Overnight® | PRIORITY_OVERNIGHT |
| FedEx Standard Overnight® | STANDARD_OVERNIGHT |
| FedEx 2Day® | FEDEX_2_DAY |
| FedEx Economy/FedEx Express Saver® [3 day] | FEDEX_EXPRESS_SAVER |
| FedEx International Ground® and FedEx Domestic Ground® | FEDEX_GROUND |
| FedEx LTL Freight Priority | FEDEX_FREIGHT_PRIORITY |
| FedEx LTL Freight Economy | FEDEX_FREIGHT_ECONOMY |
| FedEx 1Day® Freight | FEDEX_1_DAY_FREIGHT |
| FedEx 2Day® Freight | FEDEX_2_DAY_FREIGHT |
| FedEx 3Day® Freight | FEDEX_3_DAY_FREIGHT |
| FedEx® International Priority Freight | INTERNATIONAL_PRIORITY_FREIGHT |
| FedEx® International Economy Freight | INTERNATIONAL_ECONOMY_FREIGHT |
| FedEx® International Deferred Freight | FEDEX_INTERNATIONAL_DEFERRED_FREIGHT |
| FedEx International Connect Plus® | FEDEX_INTERNATIONAL_CONNECT_PLUS |
| FedEx International First® | INTERNATIONAL_FIRST |
| FedEx International Priority® Express | FEDEX_INTERNATIONAL_PRIORITY_EXPRESS |
| FedEx International Priority® | FEDEX_INTERNATIONAL_PRIORITY |
| FedEx® International Economy | INTERNATIONAL_ECONOMY |
| FedEx International Priority DirectDistribution® Freight (consolidation only) | INTERNATIONAL_DISTRIBUTION_FREIGHT |
| FedEx International Priority DirectDistribution® (consolidation only) | INTERNATIONAL_PRIORITY_DISTRIBUTION |
| FedEx International Economy DirectDistribution® (consolidation only) | INTERNATIONAL_ECONOMY_DISTRIBUTION |
| International Ground® Distribution (IGD) (consolidation only) | INTL_GROUND_DISTRIBUTION |
| Transborder distribution (consolidation only) | TRANSBORDER_DISTRIBUTION |

### LAC Region Service List

| SERVICE TYPE | ENUMERATION |
| --- | --- |
| FedEx International Priority® Express | FEDEX_INTERNATIONAL_PRIORITY_EXPRESS |
| FedEx International Priority® | FEDEX_INTERNATIONAL_PRIORITY |
| FedEx International Economy® | INTERNATIONAL_ECONOMY |
| FedEx International Priority® Freight | INTERNATIONAL_PRIORITY_FREIGHT |
| FedEx International Economy® Freight | INTERNATIONAL_ECONOMY_FREIGHT |
| FedEx® International Deferred Freight | FEDEX_INTERNATIONAL_DEFERRED_FREIGHT |
| FedEx First Overnight® (Only Mexico) | FIRST_OVERNIGHT |
| FedEx Priority Overnight® | PRIORITY_OVERNIGHT |
| FedEx Standard Overnight® | STANDARD_OVERNIGHT |
| FedEx International Priority DirectDistribution® | INTERNATIONAL_PRIORITY_DISTRIBUTION |
| FedEx Express Saver® | FEDEX_EXPRESS_SAVER |
| FedEx SameDay® City (Only for selected cities in Mexico) Note: Use Service Availability API to check if this service is available for your origin and destination postal codes. | SAME_DAY_CITY |
| FedEx 1Day® Freight (Only Mexico) | FEDEX_1_DAY_FREIGHT |
| FedEx 2Day® Freight (Only Mexico) | FEDEX_2_DAY_FREIGHT |
| FedEx First (Only Chile) | FEDEX_FIRST |
| FedEx Economy (Only Chile) | FEDEX_ECONOMY |
| FedEx Priority (Only Chile) | FEDEX_PRIORITY |
| FedEx Priority Express (Only Chile) | FEDEX_PRIORITY_EXPRESS |
| FedEx Priority Express Freight (Only Chile) | FEDEX_PRIORITY_EXPRESS_FREIGHT |
| FedEx Priority Freight (Only Chile) | FEDEX_PRIORITY_FREIGHT |
| FedEx Economy Freight (Only Chile) | FEDEX_ECONOMY_FREIGHT |

### APAC Region Service List

| SERVICE TYPE | ENUMERATION |
| --- | --- |
| FedEx International Priority® Express | FEDEX_INTERNATIONAL_PRIORITY_EXPRESS |
| FedEx International Priority® | FEDEX_INTERNATIONAL_PRIORITY |
| FedEx International First® | INTERNATIONAL_FIRST |
| FedEx International Economy® | INTERNATIONAL_ECONOMY |
| FedEx International Priority DirectDistribution® | INTERNATIONAL_PRIORITY_DISTRIBUTION |
| FedEx International Economy DirectDistribution | INTERNATIONAL_ECONOMY_DISTRIBUTION |
| FedEx International Connect Plus® | FEDEX_INTERNATIONAL_CONNECT_PLUS |
| FedEx International Priority® Freight | INTERNATIONAL_PRIORITY_FREIGHT |
| FedEx International Economy® Freight | INTERNATIONAL_ECONOMY_FREIGHT |
| FedEx® International Deferred Freight | FEDEX_INTERNATIONAL_DEFERRED_FREIGHT |
| FedEx Priority (Only Malaysia and Thailand) | FEDEX_PRIORITY |
| FedEx Priority Express (Only Thailand) | FEDEX_PRIORITY_EXPRESS |
| FedEx Priority Express Freight (Only Malaysia and Thailand) | FEDEX_PRIORITY_EXPRESS_FREIGHT |
| FedEx Priority Freight (Only Thailand) | FEDEX_PRIORITY_FREIGHT |

### MEISA Region Service List

| SERVICE TYPE | ENUMERATION |
| --- | --- |
| FedEx International Priority® Express | FEDEX_INTERNATIONAL_PRIORITY_EXPRESS |
| FedEx International Priority® | FEDEX_INTERNATIONAL_PRIORITY |
| FedEx International Economy® | INTERNATIONAL_ECONOMY |
| FedEx International Priority® Freight | INTERNATIONAL_PRIORITY_FREIGHT |
| FedEx International Economy® Freight | INTERNATIONAL_ECONOMY_FREIGHT |
| FedEx® International Deferred Freight | FEDEX_INTERNATIONAL_DEFERRED_FREIGHT |
| FedEx First Overnight® | FIRST_OVERNIGHT |
| FedEx Priority Overnight® | PRIORITY_OVERNIGHT |
| FedEx Standard Overnight® | STANDARD_OVERNIGHT |
| FedEx First (Only South Africa) | FEDEX_FIRST |
| FedEx Economy (Only South Africa) | FEDEX_ECONOMY |
| FedEx Priority (Only South Africa) | FEDEX_PRIORITY |
| FedEx Priority Express (Only South Africa) | FEDEX_PRIORITY_EXPRESS |
| FedEx Priority Express Freight (Only South Africa) | FEDEX_PRIORITY_EXPRESS_FREIGHT |
| FedEx Priority Freight (Only South Africa) | FEDEX_PRIORITY_FREIGHT |
| FedEx Economy Freight (Only South Africa) | FEDEX_ECONOMY_FREIGHT |

### EU Region International Service List

| SERVICE TYPE | ENUMERATION |
| --- | --- |
| FedEx International Priority® Express | FEDEX_INTERNATIONAL_PRIORITY_EXPRESS |
| FedEx International Priority® Freight | INTERNATIONAL_PRIORITY_FREIGHT |
| FedEx International Priority® | FEDEX_INTERNATIONAL_PRIORITY |
| FedEx International Connect Plus® | FEDEX_INTERNATIONAL_CONNECT_PLUS |
| FedEx International Economy® | INTERNATIONAL_ECONOMY |
| FedEx International Economy® Freight | INTERNATIONAL_ECONOMY_FREIGHT |
| FedEx® International Deferred Freight | FEDEX_INTERNATIONAL_DEFERRED_FREIGHT |
| FedEx International First® | INTERNATIONAL_FIRST |
| FedEx International Priority DirectDistribution® | INTERNATIONAL_PRIORITY_DISTRIBUTION |
| International Distribution Freight | INTERNATIONAL_DISTRIBUTION_FREIGHT |
| FedEx International Economy DirectDistribution | INTERNATIONAL_ECONOMY_DISTRIBUTION |
| FedEx® Regional Economy | FEDEX_REGIONAL_ECONOMY |
| FedEx® Regional Economy Freight | FEDEX_REGIONAL_ECONOMY_FREIGHT |

### EU Region Domestic Service List

| SERVICE TYPE | ENUMERATION |
| --- | --- |
| FedEx Priority Overnight® (Selected Markets) | PRIORITY_OVERNIGHT |
| FedEx First | FEDEX_FIRST |
| FedEx Priority Express | FEDEX_PRIORITY_EXPRESS |
| FedEx Priority | FEDEX_PRIORITY |
| FedEx Priority Express Freight | FEDEX_PRIORITY_EXPRESS_FREIGHT |
| FedEx Priority Freight | FEDEX_PRIORITY_FREIGHT |
| FedEx Economy (Only U.K.) | FEDEX_ECONOMY_SELECT |

### Europe New Domestic Services Portfolio

| COUNTRY | FEDEX FIRST | FEDEX PRIORITY EXPRESS | FEDEX PRIORITY | FEDEX ECONOMY | FEDEX PRIORITY EXPRESS FREIGHT | FEDEX PRIORITY FREIGHT | FEDEX PRIORITY OVERNIGHT |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Austria | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Belgium | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Czech Republic | ✓ | ✓ | ✓ |  |  | ✓ |  |
| Denmark | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Finland | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| France | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Germany | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Greece | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Hungary | ✓ | ✓ | ✓ |  |  | ✓ |  |
| Italy | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Luxembourg | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Netherlands | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Norway | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Poland | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Romania | ✓ | ✓ | ✓ |  |  | ✓ |  |
| Spain | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Sweden | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| Switzerland | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |
| United Kingdom | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |

### Package Types

| PACKAGE TYPE | ENUMERATION | SINGLE PACKAGE MAXIMUM WEIGHT |
| --- | --- | --- |
| Customer Packaging, FedEx Express® Services | YOUR_PACKAGING | 150 lbs/68 KG |
| FedEx Ground® and FedEx Home Delivery® | YOUR_PACKAGING | 150 lbs/68 KG |
| Customer Packaging, FedEx Ground® Economy (Formerly known as FedEx SmartPost®) Services | YOUR_PACKAGING | 70 lbs/32 KG |
| FedEx® Envelope | FEDEX_ENVELOPE | 1 lbs/0.5 KG |
| FedEx® Box | FEDEX_BOX | 20 lbs/9 KG |
| FedEx® Extra Small Box | FEDEX_EXTRA_SMALL_BOX | 20 lbs/9 KG |
| FedEx® Small Box | FEDEX_SMALL_BOX | 20 lbs/9 KG |
| FedEx® Medium Box | FEDEX_MEDIUM_BOX | 20 lbs/9 KG |
| FedEx® Large Box | FEDEX_LARGE_BOX | 20 lbs/9 KG |
| FedEx® Extra Large Box | FEDEX_EXTRA_LARGE_BOX | 20 lbs/9 KG |
| FedEx® 10kg Box | FEDEX_10KG_BOX | 22 lbs/10 KG |
| FedEx® 25kg Box | FEDEX_25KG_BOX | 55 lbs/25 KG |
| FedEx® Pak | FEDEX_PAK | 20 lbs/9 KG |
| FedEx® Tube | FEDEX_TUBE | 20 lbs/9 KG |

### Shipment Level Special Service Types

| SPECIAL SERVICE NAME | ENUMERATION |
| --- | --- |
| FedEx Appointment Home Delivery® | APPOINTMENT |
| Broker Select Option | BROKER_SELECT_OPTION |
| Call Before Delivery | CALL_BEFORE_DELIVERY |
| Collect on Delivery (COD) | COD |
| Custom Delivery Window | CUSTOM_DELIVERY_WINDOW |
| Cut Flowers | CUT_FLOWERS |
| Do Not Break Down Pallets | DO_NOT_BREAK_DOWN_PALLETS |
| Do Not Stack Pallets | DO_NOT_STACK_PALLETS |
| Dry Ice | DRY_ICE |
| East Coast Special Service | EAST_COAST_SPECIAL |
| Exclude From Consolidation | EXCLUDE_FROM_CONSOLIDATION |
| Extreme Length | EXTREME_LENGTH |
| FedEx Inside Delivery | INSIDE_DELIVERY |
| FedEx Inside Pickup | INSIDE_PICKUP |
| FedEx International Controlled Export | INTERNATIONAL_CONTROLLED_EXPORT_SERVICE |
| FedEx One Rate® | FEDEX_ONE_RATE |
| FedEx Third Party Consignee International Priority service (TPC) | THIRD_PARTY_CONSIGNEE |
| FedEx® Electronic Trade Documents | ELECTRONIC_TRADE_DOCUMENTS |
| Food | FOOD |
| Hold At Location | HOLD_AT_LOCATION |
| International Traffic in Arms Regulations(ITAR) | INTERNATIONAL_TRAFFIC_IN_ARMS_REGULATIONS |
| LiftGate Delivery | LIFTGATE_DELIVERY |
| LiftGate Pickup | LIFTGATE_PICKUP |
| Limited Access Delivery | LIMITED_ACCESS_DELIVERY |
| Limited Access Pickup | LIMITED_ACCESS_PICKUP |
| Over Length | OVER_LENGTH |
| Pending Shipment | PENDING_SHIPMENT |
| Pharmacy Delivery | PHARMACY_DELIVERY |
| Poison | POISON |
| Premium Home Delivery | HOME_DELIVERY_PREMIUM |
| Protection From Freezing | PROTECTION_FROM_FREEZING |
| Return Clearance | RETURNS_CLEARANCE |
| Return Shipment | RETURN_SHIPMENT |
| Saturday Delivery | SATURDAY_DELIVERY |
| Saturday Pickup | SATURDAY_PICKUP |
| Shipment Event Notification | EVENT_NOTIFICATION |
| Delivery on Invoice Acceptance | DELIVERY_ON_INVOICE_ACCEPTANCE |
| Top Load | TOP_LOAD |
| Freight Guarantee | FREIGHT_GUARANTEE |

### Freight Level Special Service Types

| SPECIAL SERVICE NAME | ENUMERATION |
| --- | --- |
| Broker Select Option | BROKER_SELECT_OPTION |
| Call Before Delivery | CALL_BEFORE_DELIVERY |
| Custom Delivery Window | CUSTOM_DELIVERY_WINDOW |
| Dangerous Goods | DANGEROUS_GOODS |
| Do Not Break Down Pallets | DO_NOT_BREAK_DOWN_PALLETS |
| Do Not Stack Pallets | DO_NOT_STACK_PALLETS |
| Extreme Length | EXTREME_LENGTH |
| Food | FOOD |
| Freight Direct | FREIGHT_DIRECT |
| Freight Guarantee | FREIGHT_GUARANTEE |
| FedEx Inside Delivery | INSIDE_DELIVERY |
| FedEx Inside Pickup | INSIDE_PICKUP |
| LiftGate Delivery | LIFTGATE_DELIVERY |
| LiftGate Pickup | LIFTGATE_PICKUP |
| Limited Access Delivery | LIMITED_ACCESS_DELIVERY |
| Limited Access Pickup | LIMITED_ACCESS_PICKUP |
| Over Length | OVER_LENGTH |
| Poison | POISON |
| Protection From Freezing | PROTECTION_FROM_FREEZING |
| Top Load | TOP_LOAD |

### Package Level Special Service Types

| SPECIAL SERVICE NAME | ENUMERATION |
| --- | --- |
| Alcohol | ALCOHOL |
| FedEx Appointment Home Delivery® | APPOINTMENT |
| Battery | BATTERY |
| Collect on Delivery | COD |
| Dangerous Goods | DANGEROUS_GOODS |
| Dry Ice | DRY_ICE |
| FedEx Priority Alert | PRIORITY_ALERT |
| FedEx Priority Alert Plus | PRIORITY_ALERT_PLUS |
| Non Standard Container | NON_STANDARD_CONTAINER |
| Piece Count Verification | PIECE_COUNT_VERIFICATION |
| Signature Option | SIGNATURE_OPTION |
| FedEx Evening Home Delivery® | EVENING |
| FedEx Date Certain Home Delivery® | DATE_CERTAIN |
| Saturday Pick Up | SATURDAY_PICKUP |
| Standalone Battery | STANDALONE_BATTERY |
| Biological Substances Category B | BIOLOGICAL_SUBSTANCES_CATEGORY_B |
| Dangerous Goods in Excepted Quantities | DANGEROUS_GOODS_IN_EXCEPTED_QUANTITIES |
| Genetically Modified Organisms and Microorganisms | GENETICALLY_MODIFIED_ORGANISMS_AND_MICROORGANISMS |
| Radioactive Excepted Package | RADIOACTIVE_EXCEPTED_PACKAGE |
| Fully Regulated Dangerous Goods (For ADR regulation within Europe, use this enum in conjunction with Options: HAZARDOUS_MATERIALS) | DANGEROUS_GOODS |
| Limited Quantities Dangerous Goods (For ADR regulation within Europe, use this enum in conjunction with Options: LIMITED_QUANTITIES_COMMODITIES) | DANGEROUS_GOODS |

### Sub Package Types

| PACKAGE TYPE |
| --- |
| BAG |
| BARREL |
| BASKET |
| BOX |
| BUCKET |
| BUNDLE |
| CAGE |
| CARTON |
| CASE |
| CHEST |
| CONTAINER |
| CRATE |
| CYLINDER |
| DRUM |
| ENVELOPE |
| HAMPER |
| OTHER |
| PACKAGE |
| PAIL |
| PALLET |
| PARCEL |
| PIECE |
| REEL |
| ROLL |
| SACK |
| SHRINKWRAPPED |
| SKID |
| TANK |
| TOTEBIN |
| TUBE |
| UNIT |

### Country Codes

| COUNTRY/TERRITORY | CODE |
| --- | --- |
| Afghanistan | AF |
| Albania | AL |
| Algeria | DZ |
| American Samoa | AS |
| Andorra | AD |
| Angola | AO |
| Anguilla | AI |
| Antarctica | AQ |
| Antigua, Barbuda | AG |
| Argentina | AR |
| Armenia | AM |
| Aruba | AW |
| Australia | AU |
| Austria | AT |
| Azerbaijan | AZ |
| Bahamas | BS |
| Bahrain | BH |
| Bangladesh | BD |
| Barbados | BB |
| Belarus | BY |
| Belgium | BE |
| Belize | BZ |
| Benin | BJ |
| Bermuda | BM |
| Bhutan | BT |
| Bolivia | BO |
| Bonaire, Caribbean Netherlands, Saba, St. Eustatius | BQ |
| Bosnia-Herzegovina | BA |
| Botswana | BW |
| Bouvet Island | BV |
| Brazil | BR |
| British Indian Ocean Territory | IO |
| Brunei | BN |
| Bulgaria | BG |
| Burkina Faso | BF |
| Burundi | BI |
| Cambodia | KH |
| Cameroon | CM |
| Canada | CA |
| Cape Verde | CV |
| Central African Republic | CF |
| Chad | TD |
| Chile | CL |
| China | CN |
| Christmas Island | CX |
| Cocos (Keeling) Islands | CC |
| Colombia | CO |
| Comoros | KM |
| Congo | CG |
| Congo, Democratic Republic Of | CD |
| Cook Islands | CK |
| Costa Rica | CR |
| Croatia | HR |
| Cuba | CU |
| Curacao | CW |
| Cyprus | CY |
| Czech Republic | CZ |
| Denmark | DK |
| Djibouti | DJ |
| Dominica | DM |
| Dominican Republic | DO |
| East Timor | TL |
| Ecuador | EC |
| Egypt | EG |
| El Salvador | SV |
| England, Great Britain, Northern Ireland, Scotland, United Kingdom, Wales, Channel Islands | GB |
| Equatorial Guinea | GQ |
| Eritrea | ER |
| Estonia | EE |
| Eswatini | SZ |
| Ethiopia | ET |
| Faeroe Islands | FO |
| Falkland Islands | FK |
| Fiji | FJ |
| Finland | FI |
| France | FR |
| French Guiana | GF |
| French Southern Territories | TF |
| Gabon | GA |
| Gambia | GM |
| Georgia | GE |
| Germany | DE |
| Ghana | GH |
| Gibraltar | GI |
| Grand Cayman, Cayman Islands | KY |
| Great Thatch Island, Great Tobago Islands, Jost Van Dyke Islands, Norman Island, Tortola Island, British Virgin Islands | VG |
| Greece | GR |
| Greenland | GL |
| Grenada | GD |
| St. Barthelemy | GP |
| Guam | GU |
| Guatemala | GT |
| Guinea | GN |
| Guinea Bissau | GW |
| Guyana | GY |
| Haiti | HT |
| Heard and McDonald Islands | HM |
| Honduras | HN |
| Hong Kong | HK |
| Hungary | HU |
| Iceland | IS |
| India | IN |
| Indonesia | ID |
| Iran | IR |
| Iraq | IQ |
| Ireland | IE |
| Israel | IL |
| Italy, Vatican City, San Marino | IT |
| Ivory Coast | CI |
| Jamaica | JM |
| Japan | JP |
| Jordan | JO |
| Kazakhstan | KZ |
| Kenya | KE |
| Kiribati | KI |
| Kuwait | KW |
| Kyrgyzstan | KG |
| Laos | LA |
| Latvia | LV |
| Lebanon | LB |
| Lesotho | LS |
| Liberia | LR |
| Libya | LY |
| Liechtenstein | LI |
| Lithuania | LT |
| Luxembourg | LU |
| Macau | MO |
| Macedonia | MK |
| Madagascar | MG |
| Malawi | MW |
| Malaysia | MY |
| Maldives | MV |
| Mali | ML |
| Malta | MT |
| Marshall Islands | MH |
| Martinique | MQ |
| Mauritania | MR |
| Mauritius | MU |
| Mayotte | YT |
| Mexico | MX |
| Micronesia | FM |
| Moldova | MD |
| Monaco | MC |
| Mongolia | MN |
| Montenegro | ME |
| Montserrat | MS |
| Morocco | MA |
| Mozambique | MZ |
| Myanmar / Burma | MM |
| Namibia | NA |
| Nauru | NR |
| Nepal | NP |
| Netherlands, Holland | NL |
| New Caledonia | NC |
| New Zealand | NZ |
| Nicaragua | NI |
| Niger | NE |
| Nigeria | NG |
| Niue | NU |
| Norfolk Island | NF |
| North Korea | KP |
| Northern Mariana Islands, Rota, Saipan, Tinian | MP |
| Norway | NO |
| Oman | OM |
| Pakistan | PK |
| Palau | PW |
| Palestine | PS |
| Panama | PA |
| Papua New Guinea | PG |
| Paraguay | PY |
| Peru | PE |
| Philippines | PH |
| Pitcairn | PN |
| Poland | PL |
| Portugal | PT |
| Puerto Rico | PR |
| Qatar | QA |
| Reunion | RE |
| Romania | RO |
| Russia | RU |
| Rwanda | RW |
| Samoa | WS |
| Sao Tome and Principe | ST |
| Saudi Arabia | SA |
| Senegal | SN |
| Serbia | RS |
| Seychelles | SC |
| Sierra Leone | SL |
| Singapore | SG |
| Slovak Republic | SK |
| Slovenia | SI |
| Solomon Islands | SB |
| Somalia | SO |
| South Africa | ZA |
| South Georgia and South Sandwich Islands | GS |
| South Korea | KR |
| Spain, Canary Islands | ES |
| Sri Lanka | LK |
| St. Christopher, St. Kitts And Nevis | KN |
| St. John, St. Thomas, U.S. Virgin Islands, St. Croix Island | VI |
| St. Helena | SH |
| St. Lucia | LC |
| St. Maarten (Dutch Control) | SX |
| St. Martin (French Control) | MF |
| St. Pierre | PM |
| St. Vincent, Union Island | VC |
| Sudan | SD |
| Suriname | SR |
| Svalbard and Jan Mayen Island | SJ |
| Sweden | SE |
| Switzerland | CH |
| Syria | SY |
| Tahiti, French Polynesia | PF |
| Taiwan | TW |
| Tajikistan | TJ |
| Tanzania | TZ |
| Thailand | TH |
| Togo | TG |
| Tokelau | TK |
| Tonga | TO |
| Trinidad and Tobago | TT |
| Tunisia | TN |
| Turkey | TR |
| Turkmenistan | TM |
| Turks and Caicos Islands | TC |
| Tuvalu | TV |
| U.S. Minor Outlying Islands | UM |
| Uganda | UG |
| Ukraine | UA |
| United Arab Emirates | AE |
| United States | US |
| Uruguay | UY |
| Uzbekistan | UZ |
| Vanuatu | VU |
| Venezuela | VE |
| Vietnam | VN |
| Wallis and Futuna Islands | WF |
| Western Sahara | EH |
| Yemen | YE |
| Zambia | ZM |
| Zimbabwe | ZW |

### Currency Codes

| CURRENCY(S) | FEDEX API CURRENCY CODES |
| --- | --- |
| Antilles Guilder | ANG |
| Argentinian Peso | ARN/ARS (Note: ARS can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| Aruban Florijn | AWG |
| Australian Dollar | AUD |
| Bahamian Dollars | BSD |
| Bahraini Dinar | BHD |
| Barbados Dollar | BBD |
| Bermuda Dollar | BMD |
| Brazilian Real | BRL |
| Brunei Dollar | BND |
| Bulgarian lev | BGN |
| Canadian Dollar | CAD |
| Cayman Dollars | CID/KYD (Note: KYD can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| Chilean Peso | CHP/CLP (Note: CLP can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| Chinese Renminbi | CNY |
| Colombian Peso | COP |
| Costa Rican Colon | CRC |
| Euro | EUR |
| Czech Republic Koruny | CZK |
| Danish Krone | DKK |
| Dominican Peso | RDD/DOP (Note: DOP can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| E. Caribbean Dollar | ECD/XCD (Note: XCD can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| Egyptian Pound | EGP |
| Great Britain Pound | UKL/GBP (Note: GBP can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| Guatemalan Quetzal | GTQ |
| Hong Kong Dollar | HKD |
| Hungarian Forint | HUF |
| Indian Rupee | INR |
| Indonesian Rupiah | IDR |
| Israeli Shekel | ILS |
| Jamaican Dollar | JAD/JMD (Note: JMD can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| Japanese Yen | JYE/JPY (Note: JPY can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| Kazachstan Tenge | KZT |
| Kenyan Schilling | KES |
| Kuwaiti Dinar | KUD/KWD (Note: KWD can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| Libyan Dinar | LYD |
| Macau Patacas | MOP |
| Malaysian Ringgits | MYR |
| Mauritian Rupee | MUR |
| Mozambican Metical | MZN |
| New Mexican Peso | NMP/MXN (Note: MXN can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| New Taiwan Dollar | NTD/TWD (Note: TWD can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| New Turkish Lira | TRY |
| New Zealand Dollar | NZD |
| Norwegian Krone | NOK |
| Pakistan Rupee | PKR |
| Panama Balboa | PAB |
| Philippine Peso | PHP |
| Polish Zloty | PLN |
| Romanian Leu | RON |
| Russian Ruble | RUB |
| Saudi Arabian Riyal | SAR |
| Singapore Dollar | SID/SGD (Note: SGD can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| Solomon Island Dollar | SBD |
| South African Rand | ZAR |
| South Korean Won | WON/KRW (Note: KRW can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| Swedish Krona | SEK |
| Swiss Francs | SFR/CHF (Note: CHF can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point) |
| Thailand Baht | THB |
| Tonga Pa'anga | TOP |
| Trinidad & Tobago Dollar | TTD |
| Uganda Schilling | UGX |
| United Arab Emirates Dirham | DHS/AED (Note: AED can only be used as a preferred currency in rate requests and in Account Registration Invoice Validation end point.) |
| United States Dollar | USD |
| Uruguay New Peso | UYU |
| Venezuela Bolivar Fuerte | VEF |
| Vietnamese Dong | VND |
| Western Samoa Tala | WST |

### Pickup Types

| DESCRIPTION | ENUMERATION | API |
| --- | --- | --- |
| Indicates FedEx will be contacted to request a pickup. | CONTACT_FEDEX_TO_SCHEDULE | Ship API |
| Indicates Shipment will be dropped off at a FedEx Location. | DROPOFF_AT_FEDEX_LOCATION | Ship API |
| Indicates Shipment will be picked up as part of a regular scheduled pickup. | USE_SCHEDULED_PICKUP | Ship API |
| Indicates the pickup will be scheduled by calling FedEx. | ON_CALL | Pickup API |
| Indicates the pickup by FedEx Ground Package Returns Program. | PACKAGE_RETURN_PROGRAM | Pickup API |
| Indicates the pickup at the regular pickup schedule. | REGULAR_STOP | Pickup API |
| Indicates the pickup specific to an Express tag or Ground call tag pickup request. Applicable only for Ship API Create Tag End point (Return shipping label). | TAG | Ship API, Pickup API |

### Notification Event Types

| DESCRIPTION | ENUMERATION | API | OPERATING COMPANY |
| --- | --- | --- | --- |
| This notification event type is sent when the package is delivered. | ON_DELIVERY | Ship API, Open-Ship API | FXG, FXE, FXSP |
| This notification event type is sent to inform when the shipment is estimated to be delivered. | ON_ESTIMATED_DELIVERY | Ship API, Open-Ship API | FXG, FXE, FXSP |
| This notification event type is sent whenever there is an exception in the delivery of the shipment. | ON_EXCEPTION | Ship API, Open-Ship API | FXG, FXE, FXSP |
| This notification event type is sent when the package is shipped. | ON_SHIPMENT | Ship API, Open-Ship API | FXG, FXE, FXSP |
| This notification event type is sent once the package is dropped off at FedEx location or has been picked up by FedEx. This notification provides a confirmation that FedEx has received the package. | ON_TENDER | Ship API, Open-Ship API | FXG, FXE, FXSP |
| This notification event type is sent when the Bill of Lading is created for the shipment. | ON_BILL_OF_LADING | Freight LTL API | FXF |
| This notification event type is sent when the pickup driver arrives at the pickup location. | ON_PICKUP_DRIVER_ARRIVED | Freight LTL API | FXF |
| This notification event type is sent when the pickup has been assigned to a pickup driver. | ON_PICKUP_DRIVER_ASSIGNED | Freight LTL API | FXF |
| This notification event type is sent when the pickup driver departs from the pickup location. | ON_PICKUP_DRIVER_DEPARTED | Freight LTL API | FXF |
| This notification event type is sent when the pickup driver is en route to the pickup location. | ON_PICKUP_DRIVER_EN_ROUTE | Freight LTL API | FXF |

### Locales

| LANGUAGE | LOCALE |
| --- | --- |
| Arabic (United Arab Emirates) | ar_AE |
| Bulgarian (Bulgaria) | bg_BG |
| Chinese (China) | zh_CN |
| Chinese (Hong Kong) | zh_HK |
| Chinese (Taiwan) | zh_TW |
| Czech (Czech Republic) | cs_CZ |
| Danish (Denmark) | da_DK |
| Dutch (Netherlands) | nl_NL |
| English (Canada) | en_CA |
| English (United Kingdom) | en_GB |
| English (United States) | en_US |
| Estonian (Estonia) | et_EE |
| Finnish (Finland) | fi_FI |
| French (Canada) | fr_CA |
| French (France) | fr_FR |
| German (Germany) | de_DE |
| German (Switzerland) | de_CH |
| Greek (Greece) | el_GR |
| Hungarian (Hungary) | hu_HU |
| Italian (Italy) | it_IT |
| Japanese (Japan) | ja_JP |
| Korean (South Korea) | ko_KR |
| Latvian (Latvia) | lv_LV |
| Lithuanian (Lithuania) | lt_LT |
| Norwegian (Norway) | no_NO |
| Polish (Poland) | pl_PL |
| Portuguese (Brazil) | pt_BR |
| Portuguese (Portugal) | pt_PT |
| Romanian (Romania) | ro_RO |
| Russian (Russia) | ru_RU |
| Slovak (Slovakia) | sk_SK |
| Slovenian (Slovenia) | sl_SI |
| Spanish (Argentina) | es_AR |
| Spanish (Mexico) | es_MX |
| Spanish (Spain) | es_ES |
| Spanish (United States) | es_US |
| Swedish (Sweden) | sv_SE |
| Thai (Thailand) | th_TH |
| Turkish (Turkey) | tr_TR |
| Ukrainian (Ukraine) | uk_UA |
| Vietnamese (Vietnam) | vi_VN |

### Tracking Status Codes

| CODE | DEFINITION | CODE | DEFINITION |
| --- | --- | --- | --- |
| Movement |  | PF | Plane in Flight |
| AA | At Airport | PL | Plane Landed |
| AC | At Canada Post facility | PM | In Progress |
| AD | At Delivery | PU | Picked Up |
| AF | At local FedEx Facility | PX | Picked up (see Details) |
| AO | Shipment arriving On-time | RR | CDO requested |
| AP | At Pickup | RM | CDO Modified |
| AR | Arrived at FedEx location | RC | CDO Cancelled |
| AX | At USPS facility | RS | Return to Shipper |
| CA | Shipment Cancelled | RP | Return label link emailed to return sender |
| CH | Location Changed | LP | Return label link cancelled by shipment originator |
| DD | Delivery Delay | RG | Return label link expiring soon |
| DE | Delivery Exception | RD | Return label link expired |
| DL | Delivered | SE | Shipment Exception |
| DP | Departed | SF | At Sort Facility |
| DR | Vehicle furnished but not used | SP | Split Status |
| DS | Vehicle Dispatched | TR | Transfer |
| DY | Delay | Regulatory |  |
| EA | Enroute to Airport | CC | Cleared Customs |
| ED | Enroute to Delivery | CD | Clearance Delay |
| EO | Enroute to Origin Airport | CP | Clearance in Progress |
| EP | Enroute to Pickup | EA | Export Approved |
| FD | At FedEx Destination | SP | Split Status |
| HL | Hold at Location | Possession |  |
| HP | Ready for Recipient Pickup | CA | Carrier |
| IT | In Transit | RC | Recipient |
| IX | In transit (see Details) | SH | Shipper |
| LO | Left Origin | CU | Customs |
| OC | Order Created | BR | Broker |
| OD | Out for Delivery | TP | Transfer Partner |
| OF | At FedEx origin facility | SP | Split status |
| OW | On the way |  |  |
| OX | Shipment information sent to USPS |  |  |
| PD | Pickup Delay |  | |

### Monitoring and Intervention Options

| SERVICE CODE | SPECIAL SERVICE OPTION | ENUMERATION |
| --- | --- | --- |
| M1 | FedEx Surround® Elite | FEDEX_SURROUND_ELITE |
| M2 | FedEx Surround® Premium | FEDEX_SURROUND_PREMIUM |
| M3 | FedEx Surround® Preferred | FEDEX_SURROUND_PREFERRED |
| M4 | FedEx Surround® Select | FEDEX_SURROUND_SELECT |

### Healthcare Identifier Options

| SERVICE CODE | SPECIAL SERVICE OPTION | ENUMERATION |
| --- | --- | --- |
| GDP | Good Distribution Practices | GOOD_DISTRIBUTION_PRACTICES |
| HCC | Clinical Trial | CLINICAL_TRIAL |
| HIM | Clinical Trials Imp | CLINICAL_TRIALS_IMP |
| HKT | Clinical Trials Kit | CLINICAL_TRIALS_KIT |
| HCP | Pharmaceuticals | PHARMACEUTICALS |
| HCT | Temperature Controlled | TEMPERATURE_CONTROLLED |
| HTU | Uncontrolled Ambient Temperature | UNCONTROLLED_AMBIENT_TEMPERATURE |
| HTH | Thirty To Forty Degrees Celsius | THIRTY_TO_FORTY_DEGREES_CELSIUS |
| HTA | Fifteen To Twenty-Five Degrees Celsius | FIFTEEN_TO_TWENTY_FIVE_DEGREES_CELSIUS |
| HTR | Two to Eight Degrees Celsius | TWO_TO_EIGHT_DEGREES_CELSIUS |
| HTP | Above Zero Degrees Celsius Protect from Freezing | ABOVE_ZERO_DEGREES_CELSIUS_PROTECT_FROM_FREEZING |
| HTF | Minus Fifteen to Minus Twenty-Five Degrees Celsius | MINUS_FIFTEEN_TO_MINUS_TWENTY_FIVE_DEGREES_CELSIUS |
| HTD | Minus Twenty to Minus Thirty Degrees Celsius | MINUS_TWENTY_TO_MINUS_THIRTY_DEGREES_CELSIUS |
| HTW | Minus Thirty to Minus Fifty Degrees Celsius | MINUS_THIRTY_TO_MINUS_FIFTY_DEGREES_CELSIUS |
| HTX | Minus Forty to Minus Sixty Degrees Celsius | MINUS_FORTY_TO_MINUS_SIXTY_DEGREES_CELSIUS |
| HTY | Minus Twenty to Minus Eighty Degrees Celsius | MINUS_TWENTY_TO_MINUS_EIGHTY_DEGREES_CELSIUS |
| HTZ | Minus Sixty to Minus Eighty Degrees Celsius | MINUS_SIXTY_TO_MINUS_EIGHTY_DEGREES_CELSIUS |
| HTC | Minus One Hundred Fifty Degrees Celsius or Below | MINUS_ONE_HUNDRED_FIFTY_DEGREES_CELSIUS_OR_BELOW |
| HCV | Vaccines | VACCINES |
| HCL | Lab Specimen | LAB_SPECIMEN |
| HES | Exempt Specimen | EXEMPT_SPECIMEN |
| HEB | Category B Specimen Un3373 | CATEGORY_B_SPECIMEN_UN3373 |
| HRS | Healthcare Radioactive Substance | HEALTHCARE_RADIOACTIVE_SUBSTANCE |
| HGT | Cell and Gene Therapy Product | CELL_AND_GENE_THERAPY_PRODUCT |
| HCD | Medical Device Critical | MEDICAL_DEVICE_CRITICAL |
| HHO | Critical Healthcare Other | CRITICAL_HEALTHCARE_OTHER |
| HCS | Surgery Kit | SURGERY_KIT |
| HMA | Medical Device Accessory | MEDICAL_DEVICE_ACCESSORY |
| HCR | Healthcare Raw Materials Other | HEALTHCARE_RAW_MATERIALS_OTHER |
| HPI | Api Active Pharma Ingredient | API_ACTIVE_PHARMA_INGREDIENT |
| HCE | Healthcare PPE | HEALTHCARE_PPE |
| HCG | Medical Device General | MEDICAL_DEVICE_GENERAL |
| HCK | Healthcare Kit other than Clinical Trial or Surgical Kit | HEALTHCARE_KIT_OTHER_THAN_CLINICAL_TRIAL_OR_SURGICAL_KIT |
| HCH | Healthcare Home Health | HEALTHCARE_HOME_HEALTH |
| HDD | Hospital Departmental Delivery | HOSPITAL_DEPARTMENTAL_DELIVERY |
| HVM | Veterinary Medicine | VETERINARY_MEDICINE |
| HCO | Healthcare Other | HEALTHCARE_OTHER |
| HPL | Packing List Return | PACKING_LIST_RETURN |
| PER | Consumables Perishables Critical | CONSUMABLES_PERISHABLES_CRITICAL |
| ASC | Aerospace Critical | AEROSPACE_CRITICAL |
| AUT | Automotive Critical | AUTOMOTIVE_CRITICAL |
| FIN | Financial Critical | FINANCIAL_CRITICAL |
| TEC | High Tech Critical | HIGH_TECH_CRITICAL |
| DAN | Industrial Critical | INDUSTRIAL_CRITICAL |

### Status Code and Statuses for Webhook Tracking Events

| TRACKING EVENTS | STATUS/EVENT CODE AND STATUSES |
| --- | --- |
| Ship | OC: Shipment information sent to FedEx DO: Dropped Off LC: Return label link cancelled by shipment originator DS: Vehicle dispatched PD: Pickup delayed CA: Shipment cancelled PU: Picked up US: Scheduled delivery updated RD: Return label link expired IP: In FedEx possession DS: Vehicle Dispatched HP: Ready for pickup RG: Return label link expiring soon RP:Return label link emailed to return sender RS:Returning package to shipper |
| In-Transit | AR: Arrived at Port of Entry AF: At local FedEx facility AC: At Cannada Post Facility IT : On the way OX: Shipment information sent to U.S. Postal Service PM: In Progress DP: Left FedEx origin facility DR: Vehicle Furnished but Not Used CP: Clearance in Progress EA: US Export Approved IN: On Demand Care completed MD: Manifest data TR: Enroute to delivery CC: International shipment release RC: Delivery Option Request Cancelled CH: Location changed |
| Delivery | OD: Out for delivery DL: Delivered |
| Exceptions | DD: Delivery Delay DE: Delivery Exception SE: Shipment Exception CD: Clearance delay |
| EDD | AE: Shipment arriving early AO: Shipment arriving On-Time DY: Delivery updated |
| CDO | RR: Delivery option requested RM: Delivery option updated HA: Hold at location request accepted RT: Return to Shipper Requested RA:Address Change Requested PR:Performed Redirect - Address Change Completed AS: Address Corrected |

### Content Types

| FILE EXTENSION | CONTENT TYPE |
| --- | --- |
| rtf | application/doc |
| doc | application/msword |
| pdf | application/pdf |
| rtf | application/rtf |
| xls | application/vnd.ms-excel |
| docx | application/vnd.openxmlformats-officedocument.wordprocessingml.document |
| xlsx | application/vnd.openxmlformats-officedocument.spreadsheetml.sheet |
| rtf | application/x-rtf |
| rtf | application/x-soffice |
| bmp | image/bmp |
| gif | image/gif |
| jpg | image/jpeg |
| png | image/png |
| tiff | image/tiff |
| txt | text/plain |
| rtf | text/richtext |
| rtf | text/rtf |

### Customer Reference Types

| ENUM | DESCRIPTION |
| --- | --- |
| CUSTOMER_REFERENCE |  |
| DEPARTMENT_NUMBER | Refer only to the 'department notes reference' utility to get the exact value for the shipment clearance type. Copy the value created and paste it in the Department number field. |
| INVOICE_NUMBER | Specifies the shipment invoice number (either GST or Non GST). |
| P_O_NUMBER | Specifies the Purchase Order number. |
| INTRACOUNTRY_REGULATORY_REFERENCE |  |
| RMA_ASSOCIATION | The RMA (Return Materials Authorization) number is assigned by you and helps identify a shipment as an authorized FedEx Return shipment. This number is printed on the FedEx label as a barcode and in human readable form and on the reference section of your FedEx invoice. |
| SHIPMENT_INTEGRITY | |

### Freight LTL Direct Special Service Categorization

| BASIC | BASIC BY APPOINTMENT | STANDARD | PREMIUM | CUSTOMER PICKUP |
| --- | --- | --- | --- | --- |
| Doorstep delivery at your front / back / garage door only to the ground level. | Doorstep delivery at your front / back / garage door only to the ground level. | Delivery inside first/ground level room in home or business place. | Delivery inside the room of your choice and an option to get the consignment unpacked. | This service type offers the consignee an option to pick up their packages from FedEx Service center by providing a valid identification document to verify their name and address. |
| 1-Person delivery | 1-Person delivery | 1-Person delivery | 2-Person delivery |  |
| Proactive notifications | Proactive notifications | Proactive notifications | Proactive notifications |  |
| No signature delivery | 2-hour delivery window | 2-hour delivery window | 2-hour delivery window |  |
| No appointment requirement | Appointment scheduling | Appointment scheduling | Appointment scheduling |  |
| Photo capture POD(Proof of Delivery) | - | FedEx Freight Direct Platform | FedEx Freight Direct Platform |  |
| Coverage : 100% throughout U.S | Coverage : 100% throughout U.S | Coverage : 84% throughout U.S | Coverage : 84% throughout U.S | |

### Freight LTL Direct Service Options and Constraints

| OPTION | ALLOWED SERVICE TYPE | DIMENSIONAL CONSTRAINTS |
| --- | --- | --- |
| BASIC_DELIVERY | Freight Economy Freight Priority | Max Weight: 2000 lbs. per unit/pallet Max Length: 96 inches |
| BASIC_PICKUP | Freight Economy Freight Priority | Max Weight: 2000 lbs. per unit/pallet Max Length: 96 inches |
| BASIC_BY_APPT_DELIVERY | Freight Economy Freight Priority | Max Weight: 2000 lbs. per unit/pallet Max Length: 96 inches Max Height: 75 inches |
| BASIC_BY_APPT_PICKUP | Freight Economy Freight Priority | Max Weight: 2000 lbs. per unit/pallet Max Length: 96 inches Max Height: 75 inches |
| STANDARD_DELIVERY | Freight Priority | Max Weight: 150 lbs. per piece Max Dimensions (Length + Girth): 250 inches L+W2+H2=Girth Max Length: 96 inches Max Weight (Pallet): 2000 lbs. per pallet Max Height: 75 inches |
| STANDARD_PICKUP | Freight Priority | Max Weight: 150 lbs. per piece Max Dimensions (Length + Girth): 250 inches L+W2+H2=Girth Max Length: 96 inches Max Weight (Pallet): 2000 lbs. per pallet Max Height: 75 inches  Dimensions are optional. Validated only if they are provided. Existing LTL freight business rules will apply. |
| PREMIUM_DELIVERY | Freight Priority | Max Weight: 300 lbs. per piece Max Dimensions (Length + Girth): 250 inches L+W2+H2=Girth Max Length: 96 inches Max Weight (Pallet): 2000 lbs. per pallet Max Height: 75 inches |
| PREMIUM_PICKUP | Freight Priority | Max Weight: 300 lbs. per piece Max Dimensions (Length + Girth): 250 inches L+W2+H2=Girth Max Length: 96 inches Max Weight (Pallet): 2000 lbs. per pallet Max Height: 75 inches |

### Label Stock Types

| TYPE OF DOCUMENT | IMAGE TYPE | STOCK TYPE |
| --- | --- | --- |
| LABEL (thermal printers) | ZPLII, EPL2 | ZPLII and EPL2: STOCK_4X6 STOCK_4X675_LEADING_DOC_TAB STOCK_4X675_TRAILING_DOC_TAB STOCK_4X8 STOCK_4X9 STOCK_4X9_LEADING_DOC_TAB STOCK_4X9_TRAILING_DOC_TAB STOCK_4X85_TRAILING_DOC_TAB STOCK_4X105_TRAILING_DOC_TAB |
| LABEL (laser printers) | PDF, PNG  Note: It is NOT recommended to use the PDF or PNG Image Type when printing on a thermal label printer as this can result in a barcode that cannot be scanned due to improper scaling. Refer to Thermal Label elements section. | PNG and PDF : PAPER_4X6 PAPER_4X675 PAPER_4X8 PAPER_4X9 PAPER_7X475 PAPER_85X11_BOTTOM_HALF_LABEL PAPER_85X11_TOP_HALF_LABEL PAPER_LETTER} |
| SHIPPING DOCUMENTS CERTIFICATE_OF_ORIGIN COMMERCIAL_INVOICE DANGEROUS_GOODS_SHIPPERS_DECLARATION CERTIFICATE_OF_ORIGIN OP_900 PRO_FORMA_INVOICE RETURN_INSTRUCTIONS | PDF PNG (for RETURN_INSTRUCTIONS only) | PAPER_LETTER |

### Retrieve ITN Endpoint Response Status and Description

| STATUS_CD | STATUS DESCRIPTION |
| --- | --- |
| A | EEI Approved |
| A1 | EEI Approved |
| AB | Unable to file |
| AC | Canceled |
| AF | Pending |
| D | Filing Canceled |
| DL | Delayed |
| DS | Pending |
| E | Errors Received |
| EC | Echo |
| ER | Errors Received |
| F | Errors Received |
| F1 | Pending |
| FC | Pending |
| H | Pending |
| HC | Pending |
| M | Pending |
| M | Pending |
| N | No EEI Required |
| NF | No EEI Filed |
| NO | Delayed |
| NR | No EEI Required |
| O | Pending |
| PA | Pending Add |
| PD | Pending Delete |
| PE | Pending Echo |
| PT | No EEI Filed |
| PU | Pending Update |
| R | Pending |
| SC | Pending |
| SD | No EEI Filed |
| SH | Errors Received |
| TE | Test |
| VE | Errors Received |
| VI | Pending |
| W | Pending |
| XD | Errors Received |
| XI | Pending |
| XR | Pending |
| XS | Errors Received |
| DP | Cancel Pending |

### Minimum Customs Value Countries or Territories for Document Shipments

| COUNTRIES/TERRITORIES |
| --- |
| Algeria |
| Armenia |
| Australia |
| Azerbaijan |
| Belarus |
| Canada |
| China |
| Czech Republic |
| El Salvador |
| Georgia |
| Indonesia |
| Japan |
| Kuwait |
| Kyrgyzstan |
| Libya |
| Moldova |
| Mongolia |
| Montenegro |
| Nepal |
| New Zealand |
| Papua New Guinea |
| Philippines |
| Romania |
| Russia |
| Samoa |
| Serbia and Montenegro |
| Slovak Republic |
| Slovenia |
| South Korea |
| Tonga |
| Turkmenistan |
| Uzbekistan |

### Ground® Economy Hub IDs

| HUB NAME | HUB ID |
| --- | --- |
| NOMA Northborough | 5015 |
| WICT Windsor | 5061 |
| SAKS Fifth Avenue | 5064 |
| EDNJ Edison | 5087 |
| NENJ Newark | 5095 |
| SBNJ South Brunswick | 5097 |
| NENY Newburgho | 5110 |
| PTPA Pittsburgh | 5150 |
| MAPA Macungie | 5183 |
| ALPA Allentown | 5185 |
| SCPA Scranton | 5186 |
| PHPA Philadelphia | 5194 |
| BAMD Baltimore | 5213 |
| MAWV Martinsburg | 5254 |
| CHNC Charlotte | 5281 |
| ATGA Atlanta | 5303 |
| ORFL Orlando | 5327 |
| TAFL Tampa | 5345 |
| METN Memphis | 5379 |
| GCOH Grove City | 5431 |
| GPOH Groveport Ohio | 5436 |
| ININ Indianapolis | 5465 |
| DTMI Detroit | 5481 |
| NBWI New Berlin | 5531 |
| MPMN Minneapolis | 5552 |
| WHIL Wheeling | 5602 |
| STMO St. Louis | 5631 |
| KCKS Kansas City | 5648 |
| DLTX Dallas | 5751 |
| HOTX Houston | 5771 |
| DNCO Denver | 5802 |
| SCUT Salt Lake City | 5843 |
| PHAZ Phoenix | 5854 |
| RENV Reno | 5893 |
| LACA Los Angeles | 5902 |
| COCA Chino | 5929 |
| SACA Sacramento | 5958 |
| SEWA Seattle | 5983 |

### Customs-Approved Document Descriptions

| DESCRIPTION |
| --- |
| Accounting Documents |
| Analysis Reports |
| Applications (Completed) |
| Bank Statements |
| Bid Quotations |
| Bills of Sale |
| Birth Certificates |
| Bonds |
| Business Correspondence |
| Checks (Completed) |
| Claim Files |
| Closing Statements |
| Conference Reports |
| Contracts |
| Correspondence/ No Commercial Value |
| Cost Estimates |
| Court Transcripts |
| Credit Applications |
| Data Sheets |
| Deeds |
| Employment Papers |
| Escrow Instructions |
| Export Papers |
| Financial Statements |
| Immigration Papers |
| Income Statements |
| Insurance Documents |
| Interoffice Memos |
| Inventory Reports |
| Invoices (Completed) |
| Leases |
| Legal Documents |
| Letter of Credit Packets |
| Letters and Cards |
| Loan Documents |
| Marriage Certificates |
| Medical Records |
| Office Records |
| Operating Agreements |
| Patent Applications |
| Permits |
| Photocopies |
| Proposals |
| Prospectuses |
| Purchase Orders |
| Quotations |
| Reservation Confirmation |
| Resumes |
| Sales Agreements |
| Sales Reports |
| Shipping Documents |
| Statements/Reports |
| Statistical Data |
| Stock Information |
| Tax Papers |
| Trade Confirmation |
| Transcripts |
| Warranty Deeds |

### Export Documents

| DESCRIPTION |
| --- |
| Some countries or products require specialized customs documentation or declarations. You can attach the documents to the package.  Following are some of the documentation:  Addendum to Textile Declaration Antique Statement Assemblers Declaration B13A Bearing Worksheet Capacitor Worksheet Certificate of Origin Certification in connection with the Importation of Films and Videos Certifications of Shipments to Syria Checklist for Bare/Populated Printed Circuit Boards Commercial Invoice Commercial Invoice for the Caribbean Common Market Conifer Solid Wood Packing Material to the People’s Republic of China Consolidated Packing List Declaration for Imported electronic products subject to radiation control standards Declaration of Biological Shipments Declaration/Commercial Invoice for Watches, Clocks, Parts of Watches and Clocks Electrical Resisters Worksheet Electronic Integrated Circuit Worksheet FDA Prior Notice Submission India Export - Annexure A India Export - Annexure B India Export - Annexure C India Export - Annexure D India Export - Annexure I India Export - Annexure II India Export - Annexure III India Export - Annexure IV India Export - Letter of Instruction Indian Commercial Invoice Interim Footwear Invoice Packing List Pre Advice Form - (UK only) Return of American Products (Military) Return of American Products (Normal) Shippers Certificate for Non-Hazardous Cargo Statement regarding the Importation of Radio Frequency Devices Toxic Substance Control Act United States - Caribbean Basin Trade Partnership Act (CBTPA) VISA IPD/IED/IDF Manifest Report Watch Information     Export documents for Switzerland  Swiss customers who wish to take advantage of FedEx Electronic Trade Documents should note that not all customs documentation can be submitted electronically without providing additional original documentation, which must be handed over to the FedEx courier at pickup. Following documents are outlined below.  Invoice (declaration of origin with original signature) Certificate of Origin Eur1, Eur-Med Cites All other permits (Swissmedic, armassuisse etc.) Form 11.51, 11.87, 11.86, 11.73 |

### Export documents for Switzerland

### Surcharges

| DESCRIPTION | ENUMERATION |
| --- | --- |
| A fee applies if the shipper does not provide a valid or accurate FedEx account number or credit card number for the billing option selected. | ACCOUNT_NUMBER_PROCESSING_FEE |
| Additional Handling fee applies to any package that measures specific dimensions, weight, and in non-standard packaging. | ADDITIONAL_HANDLING |
| If the shipper provides an incomplete or incorrect recipient address an attempt is made to correct address and complete delivery. An additional fee is assessed for delivery or attempted delivery to the corrected address. | ADDRESS_CORRECTION |
| Ancillary clearance service fee, on international shipments for clearance processing; for services requested by the shipper, recipient, or importer of record; or to recover the costs passed to FedEx by the regulatory agency for regulatory filing. This surcharge will be applicable to all U.S. inbound international shipments (Express and Ground) as part of the Custom Border Patrol Fee or U.S. Inbound Processing Fee. | ANCILLARY_FEE |
| Blind Shipment is a service where in a third party shipping service is involved to deliver the package and the end customer will not be aware of the actual address of the shipper. | BLIND_SHIPMENT |
| A service to choose your broker to handle customs process of the shipment. | BROKER_SELECT_OPTION |
| Clearance fee is added for shipments to Canada. | CANADIAN_DESTINATION |
| A surcharge applies to any piece, skid, or pallet of a shipment that is non-stackable. | CHARGEABLE_PALLET_WEIGHT |
| A clearance entry fee is charged for brokerage inclusive shipment to cover the processes required to check the Commercial Invoice submitted with the shipment and complete entry preparation procedures. | CLEARANCE_ENTRY_FEE |
| Collect on Delivery service allows you to pay for the package after the delivery. | COD |
| A service to deliver cut flowers to the destination. | CUT_FLOWERS |
| A service where surcharge is applied when dangerous goods are shipped. | DANGEROUS_GOODS |
| A Delivery Area Surcharge(DAS), Extended Delivery Area Surcharge(EDAS) and a Remote Area Surcharge(RAS) applies to package shipments destined to select U.S. ZIP codes. | DELIVERY_AREA |
| A fee may be applied after the delivery confirmation of the package. | DELIVERY_CONFIRMATION |
| A service where in the Shipper can request FedEx to have the Commercial Invoice (CI)/Delivery Challan (DC) signed by the Recipient at the time of delivery and return the signed copy back to the Shipper within 15 working days. Shipment will be delivered only against invoice acceptance by the Recipient. | DELIVERY_ON_INVOICE_ACCEPTANCE |
| During times of elevated volumes, high demand for capacity, and increased operating costs across our network, FedEx will implement Demand surcharges. | DEMAND (Formerly known as PEAK) |
| During times of elevated volumes, high demand for capacity, and increased operating costs across our network, FedEx will apply Demand Additional Handling surcharges to packages meeting the criteria and characteristics of the Additional Handling Surcharge. | DEMAND_ADDITIONAL_HANDLING (Formerly known as PEAK_ADDITIONAL_HANDLING) |
| During times of elevated volumes, high demand for capacity, and increased operating costs across our network, FedEx will apply Demand Oversize surcharges to packages meeting the criteria and characteristics of the Oversize Charge. | DEMAND_OVERSIZE (Formerly known as PEAK_OVERSIZE) |
| During times of elevated volumes, high demand for capacity, and increased operating costs across our network, FedEx will apply Demand Residential Delivery surcharges to shipments addressed to a home or private residence, including locations where a business is operated from a home, or to any shipment in which the shipper has designated the delivery address as a residence. | DEMAND_RESIDENTIAL_DELIVERY (Formerly known as PEAK_RESIDENTIAL_DELIVERY) |
| When Carrier’s pup/set or vehicle is delayed by Consignor/Consignee for loading or unloading on or near the premises of Consignor/Consignee, detention charges will begin upon expiration of the applicable free time allowed and will end when the pup/set or vehicle is loaded or unloaded and is available for movement. | DETENTION |
| A service for processing shipment documents. | DOCUMENTATION_FEE |
| A service wherein, dry ice is shipped as a special service. | DRY_ICE |
| A charge applies in addition to shipping charges once the recipient has used the label. | EMAIL_LABEL |
| An additional fee per shipment is applicable for additional security measures incorporated in a few countries | ENHANCED_SECURITY |
| A premium service delivers between 9 and 10 am in major destination cities across Europe. | EUROPE_FIRST |
| A service fee is charged if the value is exceeding the declared or actual value. | EXCESS_VALUE |
| A service offered when Consignor/Consignee requests a pup/set or vehicle to be devoted exclusively to a shipment. | EXCLUSIVE_USE |
| A service for shipping goods for exhibition. | EXHIBITION |
| A service for urgent expedited delivery to get delivery by the contracted date and time. | EXPEDITED |
| A service to export shipments. | EXPORT |
| A service where the Consignor/Consignee requests extra labor be furnished for loading, unloading, blocking or bracing, or similar services. | EXTRA_LABOR |
| A service surcharge for all Intra-India shipments with the XS service option. | EXTRA_SURFACE_HANDLING_CHARGE |
| A service of shipping any handling unit with a dimension of 12 feet or greater in length (measurement of the longest dimension). | EXTREME_LENGTH |
| A service of shipping within a specific country or region. | FEDEX_INTRACOUNTRY_FEES |
| Shipping charges when we pick up the package for return at your recipient’s location. | FEDEX_TAG |
| FedEx International Controlled Export (FICE) special service is only supported for exports originating from United States and Puerto Rico. | FICE |
| A service where recipient requests flatbed service. | FLATBED |
| Fuel surcharge percentages and amounts are assessed by FedEx. | FUEL |
| An additional fee per shipment is applicable from 6th package onwards | HIGH_DENSITY |
| A service available to customers who want to pick up a package rather than have it delivered. | HOLD_AT_LOCATION |
| Shipping and delivering of packages during most of the holidays. | HOLIDAY_DELIVERY |
| A service to offer money-back guarantee service in case of a delay during holidays. This is applicable only for FedEx Same day service. | HOLIDAY_GUARANTEE |
| A service that allows you to schedule appointments to get your packages delivered to home. | HOME_DELIVERY_APPOINTMENT |
| A service that allows you to schedule the date to deliver the package. | DATE_CERTAIN |
| A service that allows you to get package delivered between 5 and 8 p.m. | EVENING |
| A service that allows you to request FedEx to move shipments to positions beyond the adjacent loading area. The adjacent loading area is defined as a delivery site that is directly accessible from the curb and is no more than 50 feet inside the outermost door. | INSIDE_DELIVERY |
| A service that allows you to request FedEx to move shipments from positions beyond the adjacent loading area. The adjacent loading area is defined as a pickup site that is directly accessible from the curb and is no more than 50 feet inside the outermost door. | INSIDE_PICKUP |
| Exposure to and risk of any loss in excess of the maximum liability is either assumed by Shipper or transferred by Shipper to an insurance carrier through the purchase of an insurance policy. | INSURED_VALUE |
| A surcharge applied to deliver to areas of Hawaii that are remote, sparsely populated or geographically difficult to access. | INTERHAWAII |
| A service to request for lift gate to load or unload shipment at delivery. | LIFTGATE_DELIVERY |
| A service to request for lift gate to load or unload shipment at pickup. | LIFTGATE_PICKUP |
| A service to deliver the package at a limited access location (e.g., school, construction site, military base). | LIMITED_ACCESS_DELIVERY |
| A service to pickup the package at a limited access location (e.g., school, construction site, military base). | LIMITED_ACCESS_PICKUP |
| A service to include marking or tagging of pieces. | MARKING_OR_TAGGING |
| Shipments delivered to select highly congested metro ZIP codes are assessed a metro service area delivery charge. | METRO_DELIVERY |
| Shipments picked up in select highly congested metro ZIP codes are assessed a metro service area pickup charge. | METRO_PICKUP |
| A Surcharge that is applicable to the MI (Monitoring and Intervention) and related HCID (Healthcare Identifier) services. | MONITORING_AND_INTERVENTION |
| A service to include services performed during non-business hours and/or days. | NON_BUSINESS_TIME |
| Shipping items with the below criteria: a) Any item with one dimension measuring more than 34 inches. b) Any item with any two dimensions each measuring more than 17 inches. c) Any item weighing over 35 lbs. d) Any item packaged in a shipping tube. | NON_MACHINABLE |
| A service to deliver shipping to offshore. | OFFSHORE |
| A service to take remedial action to protect the integrity of a shipment’s contents in transit, at request by user. Remedial actions available include dry ice replenishment and gel pack reconditioning. | ON_DEMAND_CARE |
| Surcharge for other shipping services. | OTHER |
| If the shipment is to be delivered to a location which is remote or not easily accessible but lies in the purview of service availability region, a surcharge is applied to deliver the shipment to such locations. Based on the accessibility of the locations, they are categorized into three different tiers which are as follows:  Out of Delivery Area Tier A Out of Delivery Area Tier B Out of Delivery Area Tier C | OUT_OF_DELIVERY_AREA |
| If the shipment is to be picked from a location which is remote or not easily accessible but lies in the purview of service availability region, a surcharge is applied to pick the shipment from such locations. Based on the accessibility of the locations, they are categorized into three different tiers which are as follows:  Out of Pickup Area Tier A Out of Pickup Area Tier B Out of Pickup Area Tier C | OUT_OF_PICKUP_AREA |
| An oversize charge applies to packages that exceed 96 inches in length or 130 inches in length and girth. Please refer to the FedEx service guide for more information. | OVERSIZE |
| If the dimensional weight exceeds the actual weight, charges may be assessed based on the dimensional weight | OVER_DIMENSION |
| An overweight surcharge applies to packages that exceeds the threshold weight of 15 kgs. The charges are calculated as rate per kilogram of weight for additional kilogram over the threshold. | OVERWEIGHT |
| Shipments that contain any handling unit with a dimension of 8 feet or greater in length and less than 12 feet in length (measurement of the longest dimension). | OVER_LENGTH |
| Shipment that are provided with pallets. | PALLETS_PROVIDED |
| Shipments where the pallets are packed in shrink wrap. | PALLET_SHRINKWRAP |
| A service to provide piece count or piece verification when a breakdown of a shipment occurs at the delivery site. | PIECE_COUNT_VERIFICATION |
| A service to include delivery and pickup at a port. | PORT |
| A service to receive notification prior to the delivery. | PRE_DELIVERY_NOTIFICATION |
| A service where in your account is assigned to a global service analyst who provides around-the-clock support; advanced shipment monitoring; personalized notification in the event of a delay; and, when necessary, customized package recovery. | PRIORITY_ALERT |
| A service to protect cold-sensitive items during the entire transportation cycle during winter months. | PROTECTION_FROM_FREEZING |
| When Carrier makes a delivery at a major region shopping or outlet mall which requires driver to navigate an auto parking area to complete the pickup or delivery. | REGIONAL_MALL_DELIVERY |
| When Carrier makes a pickup at a major region shopping or outlet mall which requires driver to navigate an auto parking area to complete the pickup or delivery. | REGIONAL_MALL_PICKUP |
| Reroutes can include delivering to a different address in the same city or changing a hold-at-location instruction to courier delivery. A shipping fee is billed to the account number specified on the FedEx air way bill or shipping label for each rerouted package; it appears as an address correction on the invoice. | REROUTE |
| A service to change the schedule of a shipping. | RESCHEDULE |
| Delivery of a shipment to a residential address (including a residence used as an office). | RESIDENTIAL_DELIVERY |
| Pickup of a shipment to a residential address (including a residence used as an office). | RESIDENTIAL_PICKUP |
| A service to print labels for international returns and include them in the outbound shipment. | RETURN_LABEL |
| A service to deliver packages on Saturday. | SATURDAY_DELIVERY |
| A service to pick up packages on Saturday. | SATURDAY_PICKUP |
| A service to assemble shipments at point of origin. | SHIPMENT_ASSEMBLY |
| A service that requires your signature during delivery. | SIGNATURE_OPTION |
| An additional fee per shipment is applicable in case of single piece shipment | SINGLE_PIECE |
| A service where Consignor/Consignee requests or when the product terms of sale requires a shipment be sorted or segregated according to size, brand, flavor or other distinguishing characteristics, and placed on Consignee’s dock, pallet or similar device. | SORT_AND_SEGREGATE |
| When the shipper or recipient requests a special handling service beyond the standard pickup and delivery features of service outlined in the FedEx Service Guide. | SPECIAL_DELIVERY |
| When Customer requests flatbed service, and Carrier is able to arrange for such equipment. | SPECIAL_EQUIPMENT |
| A service that offers to deliver packages on Sunday. | SUNDAY_DELIVERY |
| A service to ship tarps to the destination. | TARP |
| The surcharge applies when an account unrelated to the shipper, as solely determined by FedEx, is billed as a third party for the shipment. It will be charged to the third-party payer. | THIRD_PARTY_CONSIGNEE |
| A service that offers shipping through Transmart. | TRANSMART_SERVICE_FEE |
| A surcharge that supports more efficient handling of oversized packages within the FedEx network and helps offset the additional handling costs associated with larger-than-limit shipments. It applies where a package exceeds the dimension restrictions for the destination country, with each country setting its own limits. Charges are assessed on a per-package basis. | UNAUTHORIZED |
| A service that offers shipping and delivery services through USPS. | USPS |
| If the weight or other information contained on the Bill of Lading is incomplete or believed to be incorrect, Carrier or Carrier’s agent will take action necessary to determine the correct information. | WEIGHING |

### Discounts

| DISCOUNT PROGRAM | DESCRIPTION |
| --- | --- |
| FedEx Ground Multiweight | FedEx Ground Multiweight is ideal for multiple-piece shipments moving as one unit to the same destination on the same day. This pricing option allows you to combine packages for a multiweight rate. Pricing is based on the combined weight of your packages. |
| Earned Discounts Pricing Program | The Earned Discounts Pricing Program awards discounts when you meet predetermined revenue levels and/or shipping criteria. You earn additional discounts as you increase shipping activity or due to specific shipment characteristics. |
| BONUS | This is based on whether packages are regularly picked up by FedEx, dropped off by you, zones, origin-destination zip codes and the shipment date. |
| COUPON | This is offered at the time of invoice creation. |
| EARNED | This is offered based on the volume of shipment you have already done with FedEx. |
| VOLUME | This is offered based on your commitment of volume with FedEx. |
| OTHER | Any other discount offered to you apart from the above categories. |

### Canada Province Codes

| PROVINCE | CODE |
| --- | --- |
| Alberta | AB |
| British Columbia | BC |
| Manitoba | MB |
| New Brunswick | NB |
| Newfoundland | NL |
| Northwest Territories | NT |
| Nova Scotia | NS |
| Nunavut | NU |
| Ontario | ON |
| Prince Edward Island | PE |
| Quebec | QC |
| Saskatchewan | SK |
| Yukon | YT |

### India State Codes

| STATE NAME | STATE CODE |
| --- | --- |
| Andaman & Nicobar (U.T) | AN |
| Andhra Pradesh | AP |
| Arunachal Pradesh | AR |
| Assam | AS |
| Bihar | BR |
| Chattisgarh | CG |
| Chandigarh (U.T.) | CH |
| Daman & Diu (U.T.) | DD |
| Delhi (U.T.) | DL |
| Dadra and Nagar Haveli (U.T.) | DN |
| Goa | GA |
| Gujarat | GJ |
| Haryana | HR |
| Himachal Pradesh | HP |
| Jammu & Kashmir | JK |
| Jharkhand | JH |
| Karnataka | KA |
| Kerala | KL |
| Lakshadweep (U.T) | LD |
| Madhya Pradesh | MP |
| Maharashtra | MH |
| Manipur | MN |
| Meghalaya | ML |
| Mizoram | MZ |
| Nagaland | NL |
| Orissa | OR |
| Punjab | PB |
| Puducherry (U.T.) | PY |
| Rajasthan | RJ |
| Sikkim | SK |
| Tamil Nadu | TN |
| Tripura | TR |
| Uttaranchal | UA |
| Uttar Pradesh | UP |
| West Bengal | WB |

### Mexico State Codes

| STATE DESCRIPTION | STATE CODE |
| --- | --- |
| Aguascalientes | AG |
| Baja California | BC |
| Baja California Sur | BS |
| Campeche | CM |
| Chiapas | CS |
| Chihuahua | CH |
| Ciudad de México | DF |
| Coahuila | CO |
| Colima | CL |
| Durango | DG |
| Estado de México | EM |
| Guanajuato | GT |
| Guerrero | GR |
| Hidalgo | HG |
| Jalisco | JA |
| Michoacán | MI |
| Morelos | MO |
| Nayarit | NA |
| Nuevo León | NL |
| Oaxaca | OA |
| Puebla | PU |
| Querétaro | QE |
| Quintana Roo | QR |
| San Luis Potosí | SL |
| Sinaloa | SI |
| Sonora | SO |
| Tabasco | TB |
| Tamaulipas | TM |
| Tlaxcala | TL |
| Veracruz | VE |
| Yucatán | YU |
| Zacatecas | ZA |

### U.S. State Codes

| STATE | CODE |
| --- | --- |
| Alabama | AL |
| Alaska | AK |
| Arizona | AZ |
| Arkansas | AR |
| California | CA |
| Colorado | CO |
| Connecticut | CT |
| Delaware | DE |
| District of Columbia | DC |
| Florida | FL |
| Georgia | GA |
| Hawaii | HI |
| Idaho | ID |
| Illinois | IL |
| Indiana | IN |
| Iowa | IA |
| Kansas | KS |
| Kentucky | KY |
| Louisiana | LA |
| Maine | ME |
| Maryland | MD |
| Massachusetts | MA |
| Michigan | MI |
| Minnesota | MN |
| Mississippi | MS |
| Missouri | MO |
| Montana | MT |
| Nebraska | NE |
| Nevada | NV |
| New Hampshire | NH |
| New Jersey | NJ |
| New Mexico | NM |
| New York | NY |
| North Carolina | NC |
| North Dakota | ND |
| Ohio | OH |
| Oklahoma | OK |
| Oregon | OR |
| Pennsylvania | PA |
| Rhode Island | RI |
| South Carolina | SC |
| South Dakota | SD |
| Tennessee | TN |
| Texas | TX |
| Utah | UT |
| Vermont | VT |
| Virginia | VA |
| Washington State | WA |
| West Virginia | WV |
| Wisconsin | WI |
| Wyoming | WY |
| Puerto Rico | PR |

### United Arab Emirates (UAE) State Codes

| CITY | CODE |
| --- | --- |
| Abu Dhabi | AB |
| Ajman | AJ |
| Dubai | DU |
| Fujairah | FU |
| Ras al-Khaimah | RA |
| Sharjah | SH |
| Umm al-Quwain | UM |

### FedEx Express International Countries/Territories Served (Ship to and from)

| COUNTRY NAME | COUNTRY CODE |
| --- | --- |
| Albania | AL |
| Afghanistan | AF |
| Algeria | DZ |
| American Samoa | AS |
| Andorra | AD |
| Angola | AO |
| Anguilla | AI |
| Antigua, Barbuda | AG |
| Argentina | AR |
| Armenia | AM |
| Aruba | AW |
| Australia, Norfolk Island | AU |
| Austria | AT |
| Azerbaijan | AZ |
| Bahamas | BS |
| Bahrain | BH |
| Bangladesh | BD |
| Barbados | BB |
| Belarus | BY |
| Belgium | BE |
| Belize | BZ |
| Benin | BJ |
| Bermuda | BM |
| Bhutan | BT |
| Bolivia | BO |
| Bosnia-Herzegovina | BA |
| Botswana | BW |
| Brazil | BR |
| Brunei | BN |
| Bulgaria | BG |
| Burkina Faso | BF |
| Burundi | BI |
| Cambodia | KH |
| Cameroon | CM |
| Canada | CA |
| Cape Verde | CV |
| Chad | TD |
| Chile | CL |
| China, People Republic Of | CN |
| Colombia | CO |
| Congo | CG |
| Congo, Dem Rep Of | CD |
| Cook Islands | CK |
| Costa Rica | CR |
| Croatia | HR |
| Curacao | CW |
| Cyprus | CY |
| Czech Republic | CZ |
| Denmark | DK |
| Djibouti | DJ |
| Dominica | DM |
| Dominican Republic | DO |
| East Timor | TL |
| Ecuador | EC |
| Egypt | EG |
| El Salvador | SV |
| England, Great Britain, Northern Ireland, Scotland, United Kingdom, Wales, Channel Islands | GB |
| Eritrea | ER |
| Estonia | EE |
| Eswatini | SZ |
| Ethiopia | ET |
| Faeroe Islands | FO |
| Fiji | FJ |
| Finland | FI |
| France | FR |
| French Guiana | GF |
| Gabon | GA |
| Gambia | GM |
| Georgia, Republic Of | GE |
| Germany | DE |
| Ghana | GH |
| Grand Cayman, Cayman Islands | KY |
| Gibraltar | GI |
| Great Thatch Island, Great Tobago Islands, Jost Van Dyke Islands, Norman Island, Tortola Island, British Virgin Islands | VG |
| Greece | GR |
| Greenland | GL |
| Grenada | GD |
| Guadeloupe | GP |
| Guam | GU |
| Guatemala | GT |
| Guinea | GN |
| Guinea Bissau | GW |
| Guyana | GY |
| Haiti | HT |
| Honduras | HN |
| Hong Kong, SAR, China | HK |
| Hungary | HU |
| Iceland | IS |
| India | IN |
| Indonesia | ID |
| Iraq | IQ |
| Ireland | IE |
| Northern Republic Of Israel | IL |
| Ivory Coast | CI |
| Jamaica | JM |
| Japan | JP |
| Jordan | JO |
| Kazakhstan | KZ |
| Kenya | KE |
| Korea, South (South Korea) | KR |
| Kuwait | KW |
| Kyrgyzstan | KG |
| Laos | LA |
| Latvia | LV |
| Lebanon | LB |
| Lesotho | LS |
| Liberia | LR |
| Libya | LY |
| Liechtenstein | LI |
| Lithuania | LT |
| Luxembourg | LU |
| Macau SAR, China | MO |
| Macedonia | MK |
| Madagascar | MG |
| Malawi | MW |
| Malaysia | MY |
| Maldives, Republic Of | MV |
| Mali | ML |
| Malta | MT |
| Marshall Islands | MH |
| Martinique | MQ |
| Mauritania | MR |
| Mauritius | MU |
| Mexico | MX |
| Micronesia | FM |
| Moldova | MD |
| Monaco | MC |
| Mongolia | MN |
| Montserrat | MS |
| Morocco | MA |
| Mozambique | MZ |
| Namibia | NA |
| Nepal | NP |
| Netherlands (Holland) | NL |
| Netherlands Antilles (Caribbean) | AN |
| New Caledonia | NC |
| New Guinea, Papua New Guinea | PG |
| New Zealand | NZ |
| Nicaragua | NI |
| Niger | NE |
| Nigeria | NG |
| Norway | NO |
| Oman | OM |
| Pakistan | PK |
| Palau | PW |
| Panama | PA |
| Paraguay | PY |
| Peru | PE |
| Philippines | PH |
| Poland | PL |
| Portugal, Azores, Madeira | PT |
| Puerto Rico | PR |
| Qatar | QA |
| Reunion Island | RE |
| Romania | RO |
| Rota, Tinian, Saipan | MP |
| Russia | RU |
| Rwanda | RW |
| Bonaire, Caribbean Netherlands, Saba, St. Eustatius | BQ |
| Saudi Arabia | SA |
| Senegal | SN |
| Serbia | RS |
| Seychelles | SC |
| Singapore | SG |
| Slovak Republic | SK |
| Slovenia | SI |
| South Africa, Republic Of | ZA |
| Spain, Canary Islands | ES |
| Sri Lanka | LK |
| Nevis, St. Christopher, St. Kitts And Nevis | KN |
| St. Croix Island, St. John, St. Thomas | VI |
| St. Lucia | LC |
| St. Maarten | SX |
| St. Martin | MF |
| St. Vincent, Union Island | VC |
| Suriname | SR |
| Sweden | SE |
| Switzerland | CH |
| Tahiti, French Polynesia | PF |
| Taiwan, China | TW |
| Tanzania | TZ |
| Thailand | TH |
| Togo | TG |
| Tonga | TO |
| Trinidad and Tobago | TT |
| Tunisia | TN |
| Turkey | TR |
| Turkmenistan | TM |
| Turks and Caicos Islands | TC |
| Tuvalu | TV |
| Uganda | UG |
| Ukraine | UA |
| United Arab Emirates | AE |
| United States | US |
| Uruguay | UY |
| Uzbekistan | UZ |
| Vanuatu | VU |
| Vatican City, San Marino, Italy | IT |
| Venezuela | VE |
| Vietnam | VN |
| Wallis and Futuna Islands | WF |
| Zambia | ZM |
| Zimbabwe | ZW |

### Countries/Territories Not Served by FedEx until further notice

| NOT SERVED |
| --- |
| Central African Republic - CF |
| Comoros |
| Cuba-CU |
| Equatorial Guinea - GQ |
| Falkland Islands |
| Guinea Bissau - GW |
| Iran - IR |
| Johnston Island |
| Kiribati |
| Korea, North (North Korea) |
| Mayotte Island |
| Myanmar (Burma) - MM |
| Nauru |
| Niue |
| Saint Pierre Et Miquelon |
| Sao Tome & Principe |
| Sierra Leone - SL |
| Solomon Islands |
| Somalia - SO |
| St. Helena (S. Atlantic) |
| Sudan - SD |
| Syria - SY |
| Tajikistan |
| Tokelau Islands |
| Turkmenistan, Republic Of - TM |
| Tuvalu |
| Wake Islands |
| Yemen, The Republic of -YE |

### Mock Tracking Numbers for FedEx Express and FedEx Ground

| SCAN EVENT | TRACKING NUMBER | TRACK BY REFERENCE INFO. | SHIPMENT ACCOUNT NUMBER | SHIP DATE | DESTINATION POSTAL CODE |
| --- | --- | --- | --- | --- | --- |
| Shipment information sent to FedEx | 449044304137821 | Customer Reference: 115380173 Ground Shipment ID: DMWsGWdnNN | 510088000 | 15-08-2020 | 33126 |
| Tendered | 149331877648230 | Ground Shipment ID: 149331877648230 | 510088000 | 15-08-2020 | 28752 |
| Picked up | 020207021381215 | Ground Shipment ID: 53089528 | 510088000 | 15-08-2020 | 30549 |
| Arrived at FedEx location | 403934084723025 | Ground Shipment ID: 403934084723025 Department: 31826722 | 510088000 | 15-08-2020 | 99206 |
| At local FedEx facility | 920241085725456 | Customer Reference: 0014243047/34684523 Ground Shipment ID: 920241085725456 | 510088000 | 15-08-2020 | 19720 |
| At destination sort facility | 568838414941 | Shipper Reference: P218101_004154359 Purchase Order: P218101_004154359 | 510088000 | 15-08-2020 | 85388 |
| Departed FedEx location | 039813852990618 | Customer Reference: 4631962 Ground Shipment ID: THE HOUSE Department: McGee Purchase Order: 3385158 | 510088000 | 15-08-2020 | 24740 |
| On FedEx vehicle for delivery | 231300687629630 | Customer Reference: W62283340102 Ground Shipment ID: 231300687629630 Purchase Order: 6228334 | 510088000 | 15-08-2020 | 33126 |
| International shipment release | 797806677146 | N/A |  |  |  |
| Customer not available or business closed (Delivery Exception 007) | 377101283611590 | Ground Shipment ID: 377101283611590 | 510088000 | 15-08-2020 | 95815 |
| Local delivery restriction (Delivery Exception 083) | 852426136339213 | Customer Reference: 118402713013 | 510088000 | 15-08-2020 | 11375 |
| Incorrect address (Delivery Exception 03) | 797615467620 | Shipper Reference: OTHER-TK104 | 510088000 | 15-08-2020 | 70810 |
| Unable to deliver (Shipment Exception 099) | 957794015041323 | N/A |  |  |  |
| Returned to sender/shipper | 076288115212522 | Invoice: 81909 | 510088000 | 15-08-2020 | 50323 |
| Clearance delay (International) | 581190049992 | N/A |  |  |  |
| Delivered | 122816215025810 | Customer Reference: PO#174724 | 510088000 | 15-08-2020 | 24273 |
| Hold at location | 843119172384577 | N/A |  |  |  |
| Shipment canceled | 070358180009382 | Customer Reference: 15241402 Ground Shipment ID: 070358180009382 | 510088000 | 15-08-2020 | 94545 |
| Duplicate Tracking Number | 713062653486 | Unique Identifier 1: 2457821000~713062653486~FX Unique Identifier 2: 2457835000~713062653486~FX |  |  | |

### Mock Tracking Numbers for FedEx Ground® Economy (Formerly known as FedEx SmartPost®)

| SCAN EVENT | TRACKING NUMBER | TRACK BY REFERENCE INFO. | SHIPMENT ACCOUNT NUMBER | SHIP DATE | DESTINATION POSTAL CODE |
| --- | --- | --- | --- | --- | --- |
| Shipment information sent to FedEx | 02394653001023698293 | Customer Reference: MLB 359926432 Invoice: 2860784 Purchase Order: 11716439 [4133589] | 510088000 | 15-08-2020 | 80010 |
| In transit | 61292701078443410536 | Customer Reference: MLB 359926432 Invoice: 2860784 Purchase Order: 11716439 [4133589] | 510088000 | 15-08-2020 | 02919 |
| Out for delivery | 61292700726653585070 | Customer Reference: PIRSQUARED | 510088000 | 15-08-2020 | 53051 |
| Delivered | 02394653018047202719 | Customer Reference: ASQBSQEQCSQ | 510088000 | 15-08-2020 | 31757 |

### Postal Aware Countries

| COUNTRY | CODE | PATTERN* |
| --- | --- | --- |
| Australia | AU | NNNN |
| Austria | AT | NNNN |
| Belgium | BE | NNNN |
| Brazil | BR | NNNNNNNN |
| Bulgaria | BG | NNNN |
| Canada | CA | ANA NAN |
| Chile | CL | NNNNNNN |
| China | CN | NNNNNN |
| Colombia | CO | NNNNNN |
| Cyprus | CY | NNNN |
| Czech Republic | CZ | NNNNN |
| Czech Republic | CZ | NNN NN |
| Denmark | DK | NNNN |
| Estonia | EE | NNNNN |
| Ecuador | EC | NNNNNN |
| Finland | FI | NNNNN |
| France | FR | NNNNN |
| Germany | DE | NNNNN |
| Greece | GR | NNNNN |
| Greece | GR | NNN NN |
| Hungary | HU | NNNN |
| India | IN | NNNNNN |
| Indonesia | ID | NNNNN |
| Ireland | IE | ANN |
| Ireland | IE | ANNXXXX |
| Ireland | IE | ANN XXXX |
| Ireland | IE | ANA |
| Ireland | IE | ANAXXXX |
| Ireland | IE | ANA XXXX |
| Italy | IT | NNNNN |
| Japan | JP | NNNNNNN |
| Korea, South | KR | NNNNN |
| Latvia | LV | NNNN |
| Lithuania | LT | NNNNN |
| Luxembourg | LU | NNNN |
| Malaysia | MY | NNNNN |
| Mexico | MX | NNNNN |
| Netherlands | NL | NNNN |
| Netherlands | NL | NNNNAA |
| Norway | NO | NNNN |
| Philippines | PH | NNNN |
| Poland | PL | NN-NNN |
| Portugal | PT | NNNN |
| Puerto Rico | US | NNNNN |
| Romania | RO | NNNNNN |
| Russia | RU | NNNNNN |
| Russia | RU | NNN-NNN |
| Saudi Arabia | SA | NNNNN |
| Singapore | SG | NNNNNN |
| Slovak Republic | SK | NNNNN |
| Slovak Republic | SK | NNN NN |
| Slovenia | SI | NNNN |
| South Africa (republic of) | ZA | NNNN |
| Spain | ES | NNNNN |
| Sweden | SE | NNNNN |
| Switzerland | CH | NNNN |
| Thailand | TH | NNNNN |
| Turkey | TR | NNNNN |
| Ukraine | UA | NNNNN |
| United Kingdom | GB | ANNAA |
| United Kingdom | GB | ANNNAA |
| United Kingdom | GB | ANANAA |
| United Kingdom | GB | AANNAA |
| United Kingdom | GB | AANA NAA |
| United Kingdom | GB | AANN NAA |
| United States | US | NNNNN |

### Track Special Handling Types

| DESCRIPTION | ENUMERATION |
| --- | --- |
| Accessible dangerous goods | ACCESSIBLE_DANGEROUS_GOODS |
| Adult Signature Required | ADULT_SIGNATURE_REQUIRED |
| Air way bill generated | AIRBILL_AUTOMATION |
| Air way bill Delivered | AIRBILL_DELIVERY |
| Alcohol | ALCOHOL |
| AM Delivery Guarantee | AM_DELIVERY_GUARANTEE |
| Appointment Delivery | APPOINTMENT_DELIVERY |
| Battery | BATTERY |
| Bill Recipient | BILL_RECIPIENT |
| Broker Select Option | BROKER_SELECT_OPTION |
| Call Before Delivery | CALL_BEFORE_DELIVERY |
| Call Tag | CALL_TAG |
| Call Tag Damage | CALL_TAG_DAMAGE |
| Chargeable Code | CHARGEABLE_CODE |
| COD | COD |
| Collect | COLLECT |
| Consolidation | CONSOLIDATION |
| Consolidation Smalls Bag | CONSOLIDATION_SMALLS_BAG |
| Currency | CURRENCY |
| Cut Flowers | CUT_FLOWERS |
| Date Certain Delivery | DATE_CERTAIN_DELIVERY |
| Delivery on Invoice Acceptance | DELIVERY_ON_INVOICE_ACCEPTANCE |
| Delivery Reattempt | DELIVERY_REATTEMPT |
| Delivery Receipt | DELIVERY_RECEIPT |
| Deliver Weekday | DELIVER_WEEKDAY |
| Direct Signature Required | DIRECT_SIGNATURE_REQUIRED |
| Domestic | DOMESTIC |
| Do Not Break Down Pallets | DO_NOT_BREAK_DOWN_PALLETS |
| Do Not Stack Pallets | DO_NOT_STACK_PALLETS |
| Dry Ice | DRY_ICE |
| Dry Ice Added | DRY_ICE_ADDED |
| East Coast Special | EAST_COAST_SPECIAL |
| Electronic COD | ELECTRONIC_COD |
| Electronic Trade Documents | ELECTRONIC_TRADE_DOCUMENTS |
| Electronic Documents With Originals | ELECTRONIC_DOCUMENTS_WITH_ORIGINALS |
| Electronic Signature Service | ELECTRONIC_SIGNATURE_SERVICE |
| Evening Delivery | EVENING_DELIVERY |
| Exclusive Use | EXCLUSIVE_USE |
| Extended Delivery | EXTENDED_DELIVERY |
| Extended Pickup | EXTENDED_PICKUP |
| Extra Labor | EXTRA_LABOR |
| Extreme Length | EXTREME_LENGTH |
| Food | FOOD |
| Fully Regulated Dangerous Goods | FULLY_REGULATED_DANGEROUS_GOODS |
| Gel Packs Added Or Replaced | GEL_PACKS_ADDED_OR_REPLACED |
| Ground Support For Ground® Economy (Formerly known as SmartPost®) | GROUND_SUPPORT_FOR_SMARTPOST |
| Guaranteed Funds | GUARANTEED_FUNDS |
| Hazardous Material | HAZMAT |
| High Floor | HIGH_FLOOR |
| Hold At Location | HOLD_AT_LOCATION |
| Holiday Delivery | HOLIDAY_DELIVERY |
| Inaccessible Dangerous Goods | INACCESSIBLE_DANGEROUS_GOODS |
| International Controlled Export Service | INTERNATIONAL_CONTROLLED_EXPORT_SERVICE |
| Inside Delivery | INSIDE_DELIVERY |
| Inside Pickup | INSIDE_PICKUP |
| International | INTERNATIONAL |
| International Controlled Export | INTERNATIONAL_CONTROLLED_EXPORT |
| International Traffic In Arms Regulations | INTERNATIONAL_TRAFFIC_IN_ARMS_REGULATIONS |
| Liftgate | LIFTGATE |
| Liftgate Delivery | LIFTGATE_DELIVERY |
| Liftgate Pickup | LIFTGATE_PICKUP |
| Limited Access Delivery | LIMITED_ACCESS_DELIVERY |
| Limited Access Pickup | LIMITED_ACCESS_PICKUP |
| Limited Quantities Dangerous Goods | LIMITED_QUANTITIES_DANGEROUS_GOODS |
| Marking Or Tagging | MARKING_OR_TAGGING |
| Net Return | NET_RETURN |
| Non Business Time | NON_BUSINESS_TIME |
| Non Standard Container | NON_STANDARD_CONTAINER |
| No Signature Required Signature Option | NO_SIGNATURE_REQUIRED_SIGNATURE_OPTION |
| Order Notify | ORDER_NOTIFY |
| Other | OTHER |
| Other Regulated Material Domestic | OTHER_REGULATED_MATERIAL_DOMESTIC |
| Package Return Program | PACKAGE_RETURN_PROGRAM |
| Piece Count Verification | PIECE_COUNT_VERIFICATION |
| Poison | POISON |
| Prepaid | PREPAID |
| Priority Alert | PRIORITY_ALERT |
| Priority Alert Plus | PRIORITY_ALERT_PLUS |
| Protection From Freezing | PROTECTION_FROM_FREEZING |
| Rail Mode | RAIL_MODE |
| Reconsignment Charges | RECONSIGNMENT_CHARGES |
| Reroute Cross Country Deferred | REROUTE_CROSS_COUNTRY_DEFERRED |
| Reroute Cross Country Expedited | REROUTE_CROSS_COUNTRY_EXPEDITED |
| Reroute Local | REROUTE_LOCAL |
| Residential Delivery | RESIDENTIAL_DELIVERY |
| Residential Pickup | RESIDENTIAL_PICKUP |
| Return Clearance | RETURNS_CLEARANCE |
| Returns Clearance Special Routing Required | RETURNS_CLEARANCE_SPECIAL_ROUTING_REQUIRED |
| Return Manager | RETURN_MANAGER |
| Saturday Delivery | SATURDAY_DELIVERY |
| Shipment Placed In Cold Storage | SHIPMENT_PLACED_IN_COLD_STORAGE |
| Single Shipment | SINGLE_SHIPMENT |
| Small Quantity Exception | SMALL_QUANTITY_EXCEPTION |
| Sort and Segregate | SORT_AND_SEGREGATE |
| Special Delivery | SPECIAL_DELIVERY |
| Special Equipment | SPECIAL_EQUIPMENT |
| Standard Ground Service | STANDARD_GROUND_SERVICE |
| Storage | STORAGE |
| Sunday Delivery | SUNDAY_DELIVERY |
| Third Party Billing | THIRD_PARTY_BILLING |
| Top Load | TOP_LOAD |
| Weekend Delivery | WEEKEND_DELIVERY |
| Weekend Pickup | WEEKEND_PICKUP |

### Harmonized System Code Unit of Measure - Table 1

| UNIT OF MEASURE DESCRIPTION | UNIT OF MEASURE VALUE |
| --- | --- |
| CARAT | AR |
| CENTIMETER | CM |
| CUBIC FOOT | CFT |
| CUBIC METER | M3 |
| DOZEN | DOZ |
| DOZEN PAIR | DPR |
| EACH | EA |
| FOOT | LFT |
| GRAM | G |
| GROSS | GR |
| KILOGRAM | KG |
| LINEAR METER | LNM |
| LITER | LTR |
| METER | M |
| MILLIGRAM | MG |
| MILLILITER | ML |
| NUMBER | NO |
| OUNCE | OZ |
| PAIR | PR |
| PIECES | PCS |
| POUND | LB |
| SQUARE FOOT | SFT |
| SQUARE METER (M2) | M2 |
| SQUARE YARD | SYD |
| YARD | YD |

### Harmonized System Code Unit of Measure - Table 2

| UNIT OF MEASURE DESCRIPTION | UNIT OF MEASURE VALUE |
| --- | --- |
| AR | CARAT |
| % ALC VOL | % ALCOHOL VOLUME |
| % VOL/HL | % VOLUME/HECTOLITER |
| 10 PAIRS | 10 PAIRS |
| 100 INTL U | 100 INTERNATIONAL UNITS |
| 100 ITEMS | HUNDRED ITEMS |
| 100 KG DNW | 100 KILOGRAMS DRAINED NET WEIGHT |
| 100 KG GRS | 100 KILOGRAMS GROSS |
| 100 KG NET | 100 KILOGRAMS NET |
| 100% VOL/L | 100% VOLUME PER LITER |
| 1000 ITEMS | THOUSAND ITEMS |
| 1000 KG PT | THOUSAND KG OR PART THEREOF |
| 1000 KILOWATT HOUR | THOUSAND KILOWATT HOUR |
| 1000 L/DEG | THOUSAND LITERS AT 15 DEGREES CELCIUS |
| 1000 NETKG | THOUSAND KG/NET |
| 1K BTU/HR | 1000 BTU/HR |
| ART | ARTICLE |
| BAG | BAG |
| BALE | BALE |
| BAR | BAR |
| BARRELS | BARREL |
| BASIC CARTONS | BASIC CARTON |
| BDU | BONE DRY UNITS OF 1089.6 KG |
| BDU/1089.6 | BDU OF 1089.6 KG |
| BOARD FT | BOARD FOOT |
| BOTTLE | BOTTLE |
| BOX | BOX |
| BTTLE/CASE | BOTTLE PER CASE |
| CARRYING CAPACITY TONES | CARRYING CAPACITY IN TONS |
| CFT | CUBIC FOOT |
| CIGARETTE | CIGARETTE |
| CM | CENTIMETER |
| CO KG | CONTENT OF COBALT IN KILOGRAMS |
| COIL | COIL |
| CONTAINER | CONTAINER |
| COOLING T | COOLING TON |
| COPPER CONTENT IN KILOGRAMS | KILOGRAM OF COPPER CONTENT |
| CR KG | KILOGRAM OF CHROMIUM CONTENT |
| CR2O3 T | TON OF CHROMIC OXIDE |
| CU KG | KILOGRAM OF COPPER CONTENT |
| CUBIC CENTIMETER | CUBIC CENTIMETER |
| CUBIC DECIMETER | CUBIC DECIMETER |
| CURIE | CURIE |
| CWT | HUNDREDWEIGHT |
| CY KG | KILOGRAM OF CLEAN YIELD |
| DAL | DECALITER |
| DECIMETER | DECIMETER |
| DOSES | DOSES |
| DOZ | DOZEN |
| DOZEN PIECES | DOZEN PIECES |
| DPR | DOZEN PAIR |
| DRAINED NET KILOGRAM WEIGHT | KILOGRAM OF DRAINED NET WEIGHT |
| DRC KG | KILOGRAM OF DRY RUBBER CONTENT |
| DUT JEWELS | DUTIABLE JEWELS |
| EA | EACH |
| FIBER METERS | FIBER METER |
| FLASK | NUMBER OF FLASKS |
| G | GRAM |
| GAL | GALLON |
| GIGABECQUEREL | GIGABECQUEREL |
| GJ | GIGAJOULE |
| GR | GROSS |
| GRAMS OF FISSILE ISOTOPES | GRAM OF FISSLE ISOTOPES |
| GRAMS OF GOLD | GRAM OF GOLD CONTENT |
| GRAMS OF SILVER | GRAM OF SILVER CONTENT |
| GROSS CNT | GROSS CONTAINER |
| GROSS KILOGRAMS | KILOGRAM GROSS |
| GROSS TONNAGE | GROSS TONNAGE |
| GWH | GIGAWATT HOUR |
| HEAD | HEAD |
| HECTOLITER | HECTOLITER |
| HNK | HANK |
| HUND CONT | HUNDRED CONTAINER |
| HUNDRED FT | HUNDRED FOOT |
| HUNDRED LB | HUNDRED POUND |
| HUNDREDS | HUNDRED |
| IBS | INDIVIDUAL BRAKE SHOES |
| IMP GAL | IMPERIAL GALLON |
| IMP/TRANS | PER IMPORT TRANSACTION |
| INCH | INCH |
| INSULIN UNITS | INSULIN UNIT |
| IR G | GRAM OF IRIDIUM CONTENT |
| ITEM | ITEM |
| JEWELS | JEWELS |
| KG | KILOGRAM |
| KG AMC | KILOGRAM OF ANHYDROUS MORPHINE CONTENT |
| KG MSC | KILOGRAM OF MILK SOLIDS CONTENT |
| KG ODE | KILOGRAM OZONE DEPLETION EQUIVALENT |
| KG P2O5 | KILOGRAM OF PHOSPHOROUS PENTOXIDE |
| KG SUB. | KILOGRAM OF SUBSTANCE |
| KG WO3 | KILOGRAM OF TUNGSTEN TRIOXIDE |
| KG/CUBIC M | KILOGRAM PER CUBIC METER |
| KG/L | KILOGRAMS PER LITER |
| KILOGRAMS AIR DRIED | KILOGRAM AIR DRY |
| KILOGRAMS OF CHLORINE CHLORIDE | KILOGRAM OF CHOLINE CHLORIDE |
| KILOGRAMS OF HYDROGEN PEROXIDE | KILOGRAM OF HYDROGEN PEROXIDE |
| KILOGRAMS OF LEAD | KILOGRAM OF LEAD CONTENT |
| KILOGRAMS OF NITROGEN | KILOGRAM OF NITROGEN |
| KILOGRAMS OF PHOSPHORUS OXIDE | KILOGRAM OF POTASSIUM OXIDE |
| KILOGRAMS OF POTASSIUM HYDROXIDE | KILOGRAM POTASSIUM HYDROXIDE |
| KILOGRAMS OF SODIUM HYDROXIDE | KILOGRAM OF SODIUM HYDROXIDE |
| KILOGRAMS OF SUBSTANCE 90% DRY | KILOGRAM OF SUBSTANCE 90% DRY |
| KILOGRAMS OF TOTAL ALCOHOL | KILOGRAM OF TOTAL ALCOHOL |
| KILOGRAMS TOTAL SUGAR | KILOGRAM OF TOTAL SUGAR |
| KILOLITER | KILOLITER |
| KILOMETER | KILOMETER |
| KILOVOLT AMPERE | KILOVOLT AMPERE |
| KTC | KILOS OF TOBACCO CONTENT |
| KW | KILOWATT |
| KWH | KILOWATT HOUR |
| L ALC | LITER ALCOHOL |
| LB | POUND |
| LINE/GROSS | LINE/GROSS |
| LITERS OF 100% ALCOHOL | LITER PURE ALCOHOL |
| LNM | LINEAR METER |
| LTR | LITER |
| M | METER |
| M. T. ADW | METRIC TON AIR DRY WEIGHT |
| M. T. DWB | METRIC TONS DRY WEIGHT BASIS |
| M2 | SQUARE METER (M2) |
| M3 | CUBIC METER |
| MATCHES | MATCHES |
| MEGABECQUEREL | MEGABECQUEREL |
| MEGALITRE | MEGALITER |
| MEGAWATT | MEGAWATT |
| METRIC CARATS | METRIC CARAT |
| METRIC TON | METRIC TON |
| METYL AMINES NET KILOGRAMS | KILOGRAM OF METHYLAMINES |
| MG | MILLIGRAM |
| MG KG | KILOGRAM OF MAGNESIUM CONTENT |
| MILLIMETER | MILLIMETER |
| MIN | MINUTE |
| MJ | MEGAJOULE |
| ML | MILLILITER |
| ML ALC | MILLILITER OF ALCOHOL CONTENT |
| MN KG | KILOGRAM OF MANGANESE CONTENT |
| MOLYBDENUM CONTENT IN KILOGRAM | KILOGRAM OF MOLYBDENUM |
| NET G | NET GRAM |
| NET KILOGRAMS | KILOGRAM NET |
| NH3 T | TON OF AMMONIA |
| NI KG | KILOGRAM OF NICKEL CONTENT |
| NO | NUMBER |
| NO. CELLS | NUMBER OF CELLS |
| NO. DOSES | NUMBER OF DOSES |
| NO. MOVES | NUMBER OF MOVEMENTS |
| NO. PAIRS | NUMBER OF PAIRS |
| NUMBER OF ROLLS | ROLLS |
| ORG COMP | VOLATILE ORGANIC COMPONENTS |
| OS G | GRAM OF OSMIUM CONTENT |
| OZ | OUNCE |
| PACK | PACK |
| PCS | PIECE |
| PD G | PALLADIUM CONTENT IN GRAMS |
| PR | PAIR |
| PROOF DL | PROOF DECALITER |
| PROOF GAL | PROOF GALLON |
| PROOF LITERS | PROOF LITER |
| PT G | GRAM OF PLATINUM CONTENT |
| QUART | QUART |
| QUINTAL | QUINTAL |
| RH G | GRAM OF RHENIUM CONTENT |
| RU G | GRAM OF RUTHENIUM CONTENT |
| RUNNING M | RUNNING METER |
| SALE KG | SALE KILOGRAM |
| SB KG | KILOGRAM OF ANTIMONY |
| SET | SET |
| SFT | SQUARE FOOT |
| SHEET | SHEET |
| SI KG | KILOGRAM OF SILICON |
| SN T | TON OF TIN CONTENT |
| SQ M | SQUARE METER (SQ M) |
| SQUARE CENTIMETER | SQUARE CENTIMETER |
| SQUARES | SQUARE |
| STEM | STEM |
| STICK | STICK |
| SUIT | SUIT |
| SYD | SQUARE YARD |
| TEN THOUS | TEN THOUSAND |
| TENS | TEN |
| THOUS BLK | THOUSAND BLOCK |
| THOUS CONT | THOUSAND CONTAINER |
| THOUS LIN | THOUSAND LINEAR INCH |
| THOUSAND CUBIC METERS | THOUSAND CUBIC METERS |
| THOUSAND LITERS | THOUSAND LITERS |
| THOUSANDS | THOUSAND |
| TJ | TERAJOULE |
| TON | TON |
| TUBE | TUBE |
| TUNGSTEN CONTENT IN KILOGRAMS | KILOGRAM OF TUNGSTEN |
| UNIT | UNIT |
| URANIUM NET KILOGRAMS | KILOGRAM OF URANIUM |
| V KG | KILOGRAM OF VANADIUM PENTOXIDE |
| VAL BN DOL | VALUE 1 BRUNEI DOL |
| VAL CURR | VALUE OF HARD CURRENCY |
| VAL METAL | VALUE OF METAL |
| VAL SG DOL | VALUE 1 SINGAPORE DOLLAR |
| VALUE | VALUE |
| VALUE/SUM | VALUE + SUM OF DUTIES AND TAXES |
| VANADIUM CONTENT IN KILOGRAMS | KILOGRAM OF VANADIUM |
| VEHICLE | VEHICLE |
| X | X |
| YD | YARD |
| ZN KG | KILOGRAM OF ZINC CONTENT |

### FedEx Express Special Handling Codes

| DESCRIPTION | CODE |
| --- | --- |
| Accessible Dangerous Goods | ADG |
| Adult Signature Required | ASR |
| Broker Select | BSO |
| Customs Cleared | CLR |
| Direct Signature Required | DSR |
| Dry Ice | ICE |
| FedEx International Controlled Export (FICE) | CES |
| Hold at Location | HLD |
| Inacessible Dangerous Goods | IDG |
| Inside Delivery | ISD |
| Piece Count Verification | PVC |
| Priority Alert | PA |
| Residential Delivery | RES |
| Third Party Consignee | TPC |

### Variable Handling Fees

| SURCHARGE | DESCRIPTION | APPLICABLE SERVICES* |
| --- | --- | --- |
| Additional Handling Surcharge | Express Package and Ground Services  A surcharge applies to any package that:  (Dimension)  1) Measures greater than 48 inches along its longest side.  2) Measures greater than 30 inches along its second-longest side.  (Weight)  3)Has an actual weight greater than 50 lbs. (U.S. Express Package Services, U.S. Ground Services). Has an actual weight greater than 70 lbs. (International Express Package Services, International Ground Service)  For Europe services  (Dimension)  1) Measures greater than 59 inches along its longest side.  2) Measures greater than 47 inches along its second-longest side.  3) Measures greater than 39 inches along its third-longest side.  4) The sum of the measurements of all three sides measures greater than 87 inches  Note: All U.S. and international packages that meet the criteria of Additional Handling Surcharge – Dimension will be subject to a 40-lb. (18 kg) minimum billable weight.  (Weight)  A surcharge is applicable, when any item has an actual weight greater than 30 Kgs (Tier 1) or than 60 Kgs (Tier 2)  (Freight)  Same as the above surcharges but only applicable for freight products. | FedEx U.S. Express Package Services®, U.S. FedEx Ground Services®, International Ground Service® , International Express Package Services®, Europe Domestic Services |
| Additional Handling Packaging Surcharge | Package shape and dimensions may change during transit, which can affect the package’s dimensional weight and surcharge eligibility. If the dimensions change during transit, FedEx may make appropriate adjustments to the shipment charges at any time. For U.S. express and ground services, this surcharge applies per piece even if multiple pieces are bundled in a shipment. We reserve the right to assess additional handling charges for packages that require special handling or that require FedEx to apply additional packaging during transit.  For Europe Shipments  A fee is applicable for the following conditions:  1) If package is not fully encased in an outer shipping container.  2) If package is encased in an outer shipping container not made of corrugated fibreboard (cardboard) materials, including but not limited to metal, wood, canvas, leather, hard plastic, soft plastic (e.g., plastic bags) or expanded polystyrene foam (e.g., Styrofoam)  3) If package is encased in an outer shipping container covered in shrink wrap or stretch wrap  4) If package is cylindrical, including (without limitation) mailing tubes, cans, buckets, barrels, drums or pails.  5) If package is bound with metal, plastic or cloth banding, or has wheels, casters, handles, or straps (including Items where the outer surface area is loosely wrapped, or where the contents protrude outside the surface area).  6) If package could become entangled in or cause damage to other Items or the FedEx sortation system. | FedEx U.S. Express Package Services®, U.S. FedEx Ground Services®, International Ground Service® , International Express Package Services®, FedEx First, FedEx Priority Express, FedEx Priority |
| Address Correction | Express.  If the shipper provides an incomplete or incorrect recipient address, we may attempt to find the correct address and complete delivery. We may use the address included in the shipper’s electronic shipment information to determine whether an address correction is necessary. We will assess a shipping fee. Address correction is also required if the recipient phone number is omitted for a Rural Delivery (RD) address or a Star Route Assignment (SRA) address in Alaska. If we are unable to complete delivery, we are not liable for failing to meet our delivery commitment. Within the U.S., we also will assess this fee if the address is a P.O. box number or P.O. box ZIP code. For international shipments destined to a P.O. box address, we may assess the fee if a valid telephone, fax or telex number is not provided for the recipient. For FedEx International Broker Select®, the address correction fee will apply if the broker’s address is incomplete or incorrect on the air waybill or other shipping documentation. If we cannot determine the correct address or cannot reach the broker, we may attempt to contact the sender for address clarification or instructions to return the shipment. If we are unable to complete delivery under these circumstances, we will not be liable for failing to meet our delivery commitment.   Ground  If the shipper provides an incomplete, incorrect or P.O. box recipient address, we may attempt to determine the correct address, complete delivery and notify the shipper of the address correction. We may use the address included in the shipper’s electronic shipment information to determine whether an address correction is necessary. We assess an additional charge for delivery or attempted delivery to the corrected address. The money-back guarantee does not apply to these shipments, and we are not liable for failing to complete delivery or meet our scheduled delivery time. | U.S. Express Package Services, U.S. Ground Services, International Express Package Services, International Ground Service, Europe Domestic Services |
| Broker Select Option | FedEx International Broker Select shipments may be routed to the FedEx in-bond location that serves your customs broker. When this occurs and you choose to have us deliver the shipment to a recipient who is served by a different FedEx location than your customs broker (such as in a different metropolitan area), a fee may apply | FedEx International Priority®, FedEx International Economy® |
| Change of Air Waybill Charge | A charge applies to any change on the air waybill due to new sender instructions received after a FedEx International Premium shipment has left the airport of departure. When the sender changes the destination and additional shipping is required, the sender is liable for the shipping charges as originally routed, plus transportation charges between the original and amended destination airports. | FedEx International Premium |
| Currency Conversion | FedEx Express customers who need their charges converted to a freely convertible currency (other than U.S. dollars) will be billed using a daily conversion rate. Our source for the daily information is OANDA, an internet exchange rate service. | International Express Package Services |
| Delivery Reattempts | Express and Ground U.S. Nonresidential Shipments.  FedEx Express and FedEx Ground will reattempt delivery if:  1) No one at the recipient address or a neighboring address is available to sign for the package and there is no signature release on file; or  2) The shipper has selected a FedEx Delivery Signature Option and no eligible recipient is available to sign for the package.     Express and Ground U.S. Residential Shipments.  FedEx Express, FedEx Ground and FedEx Home Delivery will reattempt delivery either automatically or upon request if:  1) The shipper has selected a FedEx Delivery Signature Option and no eligible recipient is available to sign for the package; or   2) We, at our sole discretion, determine the package may not be released.  Some exceptions apply. See the FedEx Express Terms and Conditions and FedEx Ground Tariff for details.      Express Packages (International)  For FedEx Express international packages, we will reattempt delivery either automatically or upon request if:  1) No one at the recipient address or a neighboring address is available to sign for the package and there is no signature release on file.  2) The shipper has selected a FedEx Delivery Signature Option and no eligible recipient is available to sign for the package; or  3) We, at our sole discretion, determine the package may not be released. If delivery of a shipment to a residential address (including a residence used as an office) cannot be completed on the initial attempt, we may, at our sole discretion, either reattempt delivery or hold the shipment until we contact the recipient for further delivery instructions. After three attempts to deliver and/or three attempts to notify the recipient, or five business days from the date of shipment, whichever occurs first, the shipment may be considered undeliverable.     Ground Packages (International)  FedEx Ground will reattempt delivery if:  1) For residential deliveries, no one at the recipient address or a neighboring address is available to sign for the package and there is no signature release on file.  2) The shipper has selected a FedEx Delivery Signature Option and no eligible recipient is available to sign for the package; or  3) We, at our sole discretion, determine the package may not be released. Some exceptions apply. See the FedEx Ground Tariff for details.     FedEx First Overnight and FedEx International First (U.S. Inbound)  We may provide three delivery attempts. For shipments that cannot be delivered on the first attempt by the FedEx First Overnight or FedEx International First commitment time, we may reattempt delivery by the FedEx Priority Overnight or FedEx International Priority commitment time on the same day as the first attempted delivery. If necessary, a second reattempt may occur by the FedEx Priority Overnight or FedEx International Priority commitment time the following business day. | FedEx Express Services, FedEx Ground Services |
| Dangerous Goods | FedEx assesses a surcharge on each package containing dangerous-goods materials. For intra-Canada shipments this surcharge is also based on the type of service provided. You cannot ship dangerous goods of any kind in the FedEx® 10kg Box or FedEx® 25kg Box with the exception of permitted IATA Section II lithium batteries | FedEx First Overnight,  FedEx Priority Overnight  FedEx Standard Overnight, FedEx 2Day A.M., FedEx 2Day, FedEx Express Saver, FedEx International First, FedEx International Priority,  FedEx International Economy. |
| Declared Value | FedEx liability for each package is limited to $100USD unless a higher value is declared and paid for. For each package exceeding $100USD in declared value, an additional amount is charged  For Europe Shipments  FedEx Express does not provide cargo liability or all-risk insurance for shipments. The sender may however, elect to pay an additional charge to specify a declared value for carriage on the air waybill, above the standard limits of liability. | FedEx Express and FedEx Ground shipments, FedEx First, FedEx Priority Express, FedEx Priority, FedEx Priority Express Freight, FedEx Priority Freight. |
| Delivery Area Surcharge | A delivery area surcharge applies to package shipments destined to select U.S. ZIP codes. In addition, a delivery area surcharge applies to FedEx Express and FedEx Ground shipments destined for areas in Alaska and Hawaii that are remote, sparsely populated or geographically difficult to access. | U.S. Express Package Services, FedEx Ground and Home Delivery. |
| Dimensional Weight | Dimensional weight is calculated by multiplying the length by width and by height of each package in inches and dividing the total by 166 (for all shipments within the U.S. and FedEx Express shipments between the U.S. and Puerto Rico) or 139 (for all U.S. export and U.S. import-rated international shipments). If the dimensional weight exceeds the actual weight, charges may be assessed based on the dimensional weight. If the chargeable weight of a FedEx Ground package exceeds 150 lbs., a prorated per-pound rate will be used. Dimensions of one-half inch or greater are rounded up to the next whole number; dimensions less than one-half inch are rounded down. The final calculation is rounded up to the next whole pound. Dimensional weight applies per package or per shipment to all FedEx Express U.S. shipments in customer packaging, and per shipment to all FedEx Express international shipments and U.S.-to-Puerto Rico shipments in customer packaging. Shipments in FedEx packaging may be subject to dimensional-weight pricing. FedEx Ground applies dimensional weight to all shipments. | FedEx Express and FedEx Ground shipments |
| Duties and Taxes | Duties and taxes, including goods and services tax (GST) and value-added tax (VAT), may be assessed on the contents of the shipment | International Express Package Services, International Ground Service |
| Electronic Export Information Filing Fee | A fee applies when FedEx files Electronic Export Information (EEI) to the U.S. government’s Automated Export System (AES) via FedEx Export AgentFile® on your behalf. | International Express Package Services, |
| Express On-Call Pickup Charge (Courier Pickup Charge) | A charge applies when you request a pickup for a FedEx Express package, including requests made using FedEx electronic shipping solutions or by calling 1.800.GoFedEx 1.800.463.3339. | U.S. Express Package Services, International Express Package Services |
| FedEx® Collect on Delivery (C.O.D.) | If the C.O.D. sender’s shipments have a 20 percent refusal rate, a higher charge may be applied. If a C.O.D. shipment is refused by the recipient, we will return the shipment to the sender. See the Collect on Delivery (C.O.D.) Service section in the FedEx Express U.S. Terms and Conditions. | FedEx Priority Overnight, FedEx Standard Overnight, FedEx 2Day A.M., FedEx 2Day, FedEx Express Saver |
| FedEx Ground® Electronic C.O.D. (E.C.O.D.) | A charge applies when you direct FedEx to collect payment from your recipient and deposit it directly into your bank account. | FedEx Ground shipments |
| FedEx® Delivery Signature Options | FedEx provides five options when you need a signature upon delivery: Indirect Signature Required, Direct Signature Required, Adult Signature Required, Service Default and No Signature Required. Indirect Signature Required is allowed to U.S. residential addresses only. Direct Signature Required is allowed to U.S. addresses and when shipping via FedEx Ground to Canadian addresses. Adult Signature Required is allowed to U.S. addresses. Depending upon the service associated with the shipment (the default signature option varies per service), the courier will perform the default request for signature collection.  For Europe domestic shipments  Adult Signature Required - FedEx will obtain a signature from any person of legal age at the delivery address, subject to sighting of a valid ID. If no one eligible to sign is available, FedEx will attempt to redeliver the package. Legal age will vary depending on the destination country/territory and is governed by local legal age of an adult, not the legal age to purchase specific products (i.e. alcohol).  Direct Signature Required - FedEx will obtain a signature from someone at the delivery address only. If no one is available to sign, FedEx will attempt to redeliver the package.  Indirect Signature Required - FedEx will obtain a signature from someone at the delivery address, from a neighbour or from a building manager. If no one is available to sign, FedEx will attempt to redeliver the package.  No Signature Required - FedEx will attempt to obtain a signature at the delivery address. If no one is available to sign, FedEx will deliver the package in a safe place without obtaining signature. | U.S. Express Package Services, U.S. Ground Services, International Ground Service, International Express Package Services, Europe Domestic Services |
| FedEx Email Return Label | A charge applies in addition to shipping charges once the recipient has used the return label.  For Europe domestic shipments: Create return or import shipping labels and allow your customers to access these electronically and edit them as needed. | FedEx First Overnight, FedEx Priority Overnight, FedEx Standard Overnight, FedEx 2Day A.M., FedEx 2Day, U.S. Ground Services, International Ground Service (Canada to U.S.), Europe Domestic Services |
| FedEx ExpressTag® | A charge applies in addition to shipping charges when we pick up the package for return at your recipient’s location. | FedEx Priority Overnight, FedEx Standard Overnight, FedEx 2Day A.M., FedEx 2Day |
| FedEx Ground® Alternate Address Pickup | We may provide pickup service to an address other than the shipping location associated with the FedEx Ground account number, upon request, for an additional charge per unique address per week. The fee applies only to FedEx Ground on-call pickups scheduled at an alternate address location by account numbers that are assessed the FedEx Ground® Automated Pickup weekly fee (see below) or the ground weekly pickup fee. | U.S. Ground Services, International Ground Service |
| FedEx Ground® Automated Pickup Weekly Fee | FedEx Ground® Automated Pickup Weekly Fee FedEx Ground may provide automated pickup service to customers for an additional charge. The weekly pickup fee will be assessed to the account number associated with the FedEx Ground Automated Pickup during the week for which one or more automated or on-call pickups are performed.  When a FedEx Ground Automated Pickup customer creates a shipment via FedEx Ship Manager® at fedex.com or FedEx Web Services before their designated shipment transmission time, a pickup will occur the same day. FedEx Ship Manager® Software or FedEx Ship Manager® Server users must upload or close shipment information prior to their designated shipment transmission time in order to receive a pickup the same day. | U.S. Ground Services, International Ground Services |
| FedEx Ground® Call Tag | A charge applies in addition to shipping charges when FedEx picks up the packages for return at your recipient’s location. This service is only available for U.S. shipments | FedEx Ground, FedEx Home Delivery |
| FedEx Home Delivery Convenient Delivery Options | You can choose FedEx Home Delivery convenient delivery options:  FedEx Date Certain Home Delivery® FedEx Evening Home Delivery® FedEx Appointment Home Delivery® | FedEx Home Delivery |
| FedEx International Controlled Export (FICE) | A charge applies when you select the FedEx International Controlled Export option for shipments moving under a U.S. State Department (DSP) license or U.S. Drug Enforcement Administration permits. | FedEx International Priority |
| FedEx On Demand Care | A charge applies if at your request we take remedial action to protect the integrity of a shipment’s contents in transit. Remedial actions available include dry ice replenishment and gel pack reconditioning.  For Europe domestic shipments:  Dry Ice: On Demand Care (ODC) is a fee-based service for non-food items providing intervention services such as Dry Ice replenishment and gel pack reconditioning/exchange, to protect the integrity of temperature-controlled shipments delayed in-transit  Gel Pak: On Demand Care (ODC) is a fee-based service for non-food items providing intervention services such as Dry Ice replenishment and gel pack reconditioning/exchange, to protect the integrity of temperature-controlled shipments delayed in-transit.  Cold Storage: On Demand Care (ODC) is a fee-based service for non-food items providing intervention services such as Dry Ice replenishment and gel pack reconditioning/exchange, to protect the integrity of temperature-controlled shipments delayed in-transit. | U.S. Express Package Services, International Express Package Services, Europe Domestic Services |
| FedEx® Print Return Label | A charge applies in addition to shipping charges once the recipient has used the return label.  For Europe domestic shipments: Create return or import shipping labels and allow your customers to access these electronically and edit them as needed. | U.S. Express Package Services, International Express Package Services, Europe Domestic Services |
| Fuel Surcharge | FedEx reserves the right to assess fuel and other surcharges on shipments, without notice. FedEx will determine the amount and duration of any such surcharges at FedEx sole discretion. By tendering your shipment to FedEx, you agree to pay the surcharges, as determined by FedEx. | FedEx Express Services, FedEx Ground Services |
| Hazardous Materials | We assess a surcharge on each package containing hazardous materials. Materials classified as ORM-D or Limited Quantity are the only hazardous materials you can ship via FedEx Home Delivery and FedEx International Ground (no surcharge applies for ORM-D and Limited Quantity). | FedEx Ground, FedEx Home Delivery, International Ground Service |
| Oversize Charge | An oversize charge applies to packages that exceed 96 inches in length or 130 inches in length and girth. Rating will be based on the greater of the package’s actual rounded weight or dimensional weight, subject to a 90-lbs. minimum billable weight. FedEx Express international shipments will not be subject to a 90-lbs. minimum billable weight. For more information about extra-large packages, see the Extra-Large Packages and the Package Restrictions (Size and Weight) sections in the FedEx Express Terms and Conditions, and the Package Restrictions (Size and Weight) section in the FedEx Ground Tariff. Package shape and dimensions may change during transit, which can affect the package’s dimensional weight and surcharge eligibility. If the dimensions change during transit, FedEx may make appropriate adjustments to the shipment charges at any time.  For Europe Services  Oversize surcharge is applicable when any item measures along its longest side greater than 59 inches. | U.S. Express Package Services, FedEx Ground, International Ground Service, FedEx Home Delivery, International Express Package Services, Europe Domestic Services |
| Residential Surcharge | A residential package surcharge applies to shipments to a home or private residence, including locations where a business is operated from a home. | FedEx Express, FedEx Ground, Europe Domestic Services |
| Rural Delivery (Alaska and Hawaii) | A surcharge applies for delivery to select rural postal codes in Alaska and Hawaii. | FedEx Ground U.S. |
| Saturday Delivery | For U.S. package shipments, Saturday delivery is available with FedEx First Overnight, FedEx Priority Overnight and FedEx 2Day for an additional charge. If FedEx does not deliver or attempt delivery on Saturday because the shipper or recipient requested a later delivery or informed FedEx that the recipient location is closed on Saturday, a Saturday delivery fee will still be charged. If failure to deliver on a Saturday results from an unexcused service failure, the FedEx Money-Back Guarantee may apply.  For Europe domestic shipments, a surcharge might apply to a delivery performed on a Saturday. Service availability may vary according to destination. When Saturday is a normal business day at the destination country/territory, Saturday Delivery does not have to be marked on the Air Waybill, and the surcharge does not apply. | FedEx First Overnight, FedEx Priority Overnight, FedEx 2Day (charge is not applied to shipments rated as FedEx Express Multiweight) FedEx International Priority, Europe Domestic Services |
| Saturday Pickup | An additional charge also applies when you request Saturday pickup for FedEx Express U.S., FedEx International First or FedEx International Priority shipments.  For Europe domestic shipments, a surcharge might apply to a pickup performed on a Saturday. Service availability may vary according to place of collection. When Saturday is a normal business day at the destination country/territory, Saturday Pickup does not have to be marked on the Air Waybill, and the surcharge does not apply. | U.S. Express Package Services, FedEx International First, FedEx International Priority, Europe Domestic Services |
| Dry Ice Surcharge | A surcharge will be applied for dry-ice packaging. FedEx Express Dangerous Goods services allow the shipping of regulated dangerous substances and materials including door‑to‑door delivery and customs clearance. Applicable fee depends on the Dangerous Goods type:  Dry Ice (DI) Dry ice is a hazardous commodity that requires special handling, subject to simplified regulations. This means it can be offered in locations where fully regulated Dangerous Goods services are not offered. In case a shipment contains Dry Ice or Lithium Battery Section II with another Dangerous Good, only the respective (Accessible or Inaccessible) Dangerous Goods surcharge applies. | FedEx International Priority, FedEx International Economy, FedEx First, FedEx Priority Express, FedEx Priority, FedEx Priority Express Freight, FedEx Priority Freight |
| Receiver Pay (Bill Consignee) | A surcharge will apply to shipments that are billed to consignee. The surcharge applies when an account unrelated to the shipper, as solely determined by FedEx, is billed as a consignee for the shipment. It will be charged to the consignee payer. | Europe Domestic Services |
| Out of Delivery Area (ODA) | If the shipment is to be delivered to a location which is remote or not easily accessible but lies in the purview of service availability region, a surcharge is applied to deliver the shipment to such locations. | Europe Domestic Services |
| Out of Pickup Area (OPA) | If the shipment is to be picked from a location which is remote or not easily accessible but lies in the purview of service availability region, a surcharge is applied to pick the shipment from such locations. | Europe Domestic Services |
| Demand Surcharge | During times of elevated volumes, high demand for capacity, and increased operating costs across our network, FedEx will implement Demand surcharges. | Europe Domestic Services |
| Multipiece Shipments | An additional fee per package is applicable from 2nd package onwards. | Europe Domestic Services |
| Non-Electronic consignment Note | A fee applied where data is not submitted electronically i.e. handwritten consignments. | Europe Domestic Services |
| Overweight Surcharge | An overweight surcharge applies to packages that exceeds the threshold weight of 15 kgs. The charges are calculated as rate per kilogram of weight for additional kilogram over the threshold. | Europe Domestic Services |
| High Density Surcharge | An additional fee per shipment is applicable from 6th package onwards | Europe Domestic Services |
| Delivery on Invoice Acceptance | A fee is applicable for, FedEx to have the Commercial Invoice (CI) / Delivery Challan (DC) signed by the recipient at the time of delivery and forward the signed copy of the CI / DC back to customer | FedEx First, FedEx Priority Express, FedEx Priority |
| Non-stackable | A surcharge applies to any FedEx International Priority Freight, FedEx International Economy Freight and FedEx Regional Economy Freight shipment containing at least one piece, skid or pallet that is non-stackable. Non-stackable means that a piece, skid or pallet cannot be stacked vertically in a safe and secure manner. Examples include, but are not limited to, non-stackable freight handling units that:  1) do not have a flat and stable top or base.  2) may be damaged if another piece is loaded on them.  3) have flat and stable loading surfaces but are too narrow to safely and securely support other freight handling units (e.g. 1-2 barrels or drums).  This surcharge applies once per shipment, even if multiple pieces identified as non-stackable are bundled in a shipment. | FedEx Priority Express Freight, FedEx Priority Freight |
| Hold at Location | As an alternative to a business or residential delivery, you can choose Hold at FedEx Location (HAL), and have recipients pick up their shipments at a FedEx Express location. Subject to availability. | FedEx Priority Express, FedEx Priority, FedEx Priority Express Freight, FedEx Priority Freight |
| Lithium Battery | FedEx Express Dangerous Goods services allow the shipping of regulated dangerous substances and materials including door‑to‑door delivery and customs clearance.  Applicable fee depends on the Dangerous Goods type:  Lithium Batteries Section II (ELB)  Four commodities are covered:  1. Lithium Ion packed with equipment (UN 3481 - Packing Instruction 966).  2. Lithium Ion contained in equipment (UN 3481 - Packing Instruction 967).  3. Lithium Metal packed with equipment (UN 3091 - Packing Instruction 969).  4. Lithium Metal contained in equipment (UN 3091 - Packing Instruction 970).  In case a shipment contains Dry Ice or Lithium Battery Section II with another Dangerous Good, only the respective (Accessible or Inaccessible) Dangerous Goods surcharge applies. | FedEx First, FedEx Priority Express, FedEx Priority, FedEx Priority Express Freight, FedEx Priority Freight |
| Priority Alert | FedEx Priority Alert (FPA) is a specialised, fee-based and contract-only service specifically designed for customers requiring a high degree of shipment visibility and delivery compliance. By combining special boarding, enhanced shipment status tracking and operational recovery procedures, FPA provides an enhanced and reliable solution for your shipment. | Europe Domestic Services |
| Priority Alert Plus | Our FedEx Priority Alert Plus (FPA+) service includes all the features of FPA as well as pro-active intervention capabilities for temperature sensitive shipments. With FPA+, re-icing, gel pack replenishments & cold storage are included as standard in the service. | Europe Domestic Services |
| U.S. Inbound Processing Fee | A Custom Border Patrol (CBP) regulatory fee is assessed on U.S. Import shipments in connection with the processing of those shipments for clearance. This Fee will be applicable to all U.S. Inbound international shipments (Express and Ground). This processing fee is NOT applicable for shipments from Puerto Rico to U.S, U.S to Puerto Rico, and U.S. origin shipments. | FedEx International First, FedEx International Priority Express, FedEx International Priority, FedEx International Economy, FedEx International Ground, and International Express Freight Services |

### Vague Commodity Descriptions

| VAGUE COMMODITY | VAGUE COMMODITY DESCRIPTION |
| --- | --- |
| A/C Parts | A/C Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Brake Caliper for Aircraft. Clearance delays may result if the contents are not completely and accurately described. |
| AC Parts | AC Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Brake Caliper for Aircraft. Clearance delays may result if the contents are not completely and accurately described. |
| Accessories | Accessories is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Advertising Material | Advertising Material is an incomplete description and not accepted by Customs. An example of an acceptable description is Plastic Advertising Signs. Clearance delays may result if the contents are not completely and accurately described. |
| Aircraft Parts | Aircraft Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Brake Caliper for Aircraft. Clearance delays may result if the contents are not completely and accurately described. |
| Aircraft Spare Parts | Aircraft Spare Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Brake Caliper for Aircraft. Clearance delays may result if the contents are not completely and accurately described. |
| Aircraft Spares | Aircraft Spares is an incomplete description and not accepted by Customs. An example of an acceptable description is Brake Caliper for Aircraft. Clearance delays may result if the contents are not completely and accurately described. |
| Antibodies | Antibodies is an incomplete description and not accepted by Customs. An example of an acceptable description is Human Antibodies. Clearance delays may result if the contents are not completely and accurately described. |
| Antibody | Antibody is an incomplete description and not accepted by Customs. An example of an acceptable description is Human Antibody. Clearance delays may result if the contents are not completely and accurately described. |
| Apparel | Apparel is an incomplete description and not accepted by Customs. An example of an acceptable description is Men's T-shirt. Clearance delays may result if the contents are not completely and accurately described. |
| Appliance | Appliance is an incomplete description and not accepted by Customs. An example of an acceptable description is Industrial Dishwasher. Clearance delays may result if the contents are not completely and accurately described. |
| Appliances | Appliances is an incomplete description and not accepted by Customs. An example of an acceptable description is Industrial Dishwasher. Clearance delays may result if the contents are not completely and accurately described. |
| Art | Art is an incomplete description and not accepted by Customs. An example of an acceptable description is Water Color Painting. Clearance delays may result if the contents are not completely and accurately described. |
| As Per Attached INV | As Per Attached INV is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Assorted Merchandise | Assorted Merchandise is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Auto Part | Auto Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Used Auto Parts: Remanufactured Alternator. Clearance delays may result if the contents are not completely and accurately described. |
| Auto Parts | Auto Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Used Auto Parts: Remanufactured Alternator. Clearance delays may result if the contents are not completely and accurately described. |
| Automotive Parts | Automotive Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Used Auto Parts: Remanufactured Alternator. Clearance delays may result if the contents are not completely and accurately described. |
| Autoparts | Autoparts is an incomplete description and not accepted by Customs. An example of an acceptable description is Used Auto Parts: Remanufactured Alternator. Clearance delays may result if the contents are not completely and accurately described. |
| Bag | Bag is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Battery | Battery is an incomplete description and not accepted by Customs. An example of an acceptable description is Car Battery. Clearance delays may result if the contents are not completely and accurately described. |
| Bearing | Bearing is an incomplete description and not accepted by Customs. An example of an acceptable description is Ball Bearing. Clearance delays may result if the contents are not completely and accurately described. |
| Belts | Belts is an incomplete description and not accepted by Customs. An example of an acceptable description is Leather Belts. Clearance delays may result if the contents are not completely and accurately described. |
| Box | Box is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Brake Parts | Brake Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Brake Caliper. Clearance delays may result if the contents are not completely and accurately described. |
| Brake | Brake is an incomplete description and not accepted by Customs. An example of an acceptable description is Automobile Brake. Clearance delays may result if the contents are not completely and accurately described. |
| Business Correspondence | Business Correspondence is an incomplete description and not accepted by Customs. An example of an acceptable description is Legal Contract. Clearance delays may result if the contents are not completely and accurately described. |
| Cable | Cable is an incomplete description and not accepted by Customs. An example of an acceptable description is Copper Cable. Clearance delays may result if the contents are not completely and accurately described. |
| Cap | Cap is an incomplete description and not accepted by Customs. An example of an acceptable description is Baseball Caps. Clearance delays may result if the contents are not completely and accurately described. |
| Caps | Caps is an incomplete description and not accepted by Customs. An example of an acceptable description is Baseball Caps. Clearance delays may result if the contents are not completely and accurately described. |
| Carton | Carton is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| CD | CD is an incomplete description and not accepted by Customs. An example of an acceptable description is Jazz Music CD. Clearance delays may result if the contents are not completely and accurately described. |
| CDs | CDs is an incomplete description and not accepted by Customs. An example of an acceptable description is Jazz Music CDs. Clearance delays may result if the contents are not completely and accurately described. |
| Cell Line | Cell Line is an incomplete description and not accepted by Customs. Specify the name of the material |
| Cells | Cells is an incomplete description and not accepted by Customs. Specify the name of the material |
| Chemical | Chemical is an incomplete description and not accepted by Customs. Please provide the actual chemical name and UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Chemicals | Chemicals is an incomplete description and not accepted by Customs. Please provide the actual chemical name and UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Chip | Chip is an incomplete description and not accepted by Customs. An example of an acceptable description is Computer Integrated Circuit. Clearance delays may result if the contents are not completely and accurately described. |
| Christmas Gifts | Christmas Gift is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| CI Attached | CI Attached is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Civil Aircraft Parts | Civil Aircraft Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Brake Caliper for Aircraft. Clearance delays may result if the contents are not completely and accurately described. |
| Civil Aircraft Spares | Civil Aircraft Spares is an incomplete description and not accepted by Customs. An example of an acceptable description is Brake Caliper for Aircraft. Clearance delays may result if the contents are not completely and accurately described. |
| Clothes / Textiles | Clothes / Textiles is an incomplete description and not accepted by Customs. An example of an acceptable description is Men's T-shirts. Clearance delays may result if the contents are not completely and accurately described. |
| Clothes | Clothes is an incomplete description and not accepted by Customs. An example of an acceptable description is Men's T-shirts. Clearance delays may result if the contents are not completely and accurately described. |
| Clothing and Accessories | Clothing and Accessories is an incomplete description and not accepted by Customs. An example of an acceptable description is Men's T-shirts. Clearance delays may result if the contents are not completely and accurately described. |
| Clothing | Clothing is an incomplete description and not accepted by Customs. An example of an acceptable description is Men's T-shirts. Clearance delays may result if the contents are not completely and accurately described. |
| Comat | Comat is an incomplete description and not accepted by Customs. An example of an acceptable description is Office Correspondence. Clearance delays may result if the contents are not completely and accurately described. |
| Commercial Invoice | Commercial Invoice is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Components | Components is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Computer Parts | Computer Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is PC Cooling Motor for Motherboard. Clearance delays may result if the contents are not completely and accurately described. |
| Computer Peripherals | Computer Peripherals is an incomplete description and not accepted by Customs. An example of an acceptable description is Computer CD Players. Clearance delays may result if the contents are not completely and accurately described. |
| Connector | Connector is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Cosmetic Products | Cosmetic Products is an incomplete description and not accepted by Customs. An example of an acceptable description is Women's Fragrance. Clearance delays may result if the contents are not completely and accurately described. |
| Cosmetics | Cosmetics is an incomplete description and not accepted by Customs. An example of an acceptable description is Women's Fragrance. Clearance delays may result if the contents are not completely and accurately described. |
| Culture | Culture is an incomplete description and not accepted by Customs. Specify the name of the material. |
| Dangerous Good | Dangerous Good is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Dangerous Goods | Dangerous Goods is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Data Processing Part | Data Processing Part is an incomplete description and not accepted by Customs. An example of an acceptable description is PC Cooling Motor for Motherboard. Clearance delays may result if the contents are not completely and accurately described. |
| Data Processing Parts | Data Processing Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is PC Cooling Motor for Motherboard. Clearance delays may result if the contents are not completely and accurately described. |
| Defective Goods | Defective Goods is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| DESC N | DESC N is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| DESCRI | DESCRI is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| DG | DG is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| DGs | DGs is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Disk | Disk is an incomplete description and not accepted by Customs. An example of an acceptable description is Business Correspondence on a Floppy Disk. Clearance delays may result if the contents are not completely and accurately described. |
| Disks | Disks is an incomplete description and not accepted by Customs. An example of an acceptable description is Business Correspondence on Floppy Disks. Clearance delays may result if the contents are not completely and accurately described. |
| Display | Display is an incomplete description and not accepted by Customs. An example of an acceptable description is Liquid Crystal Display (LCD) - Desktop Projector. Clearance delays may result if the contents are not completely and accurately described. |
| DNA | DNA is an incomplete description and not accepted by Customs. Specify the name of the material. |
| Doc | Doc is an incomplete description and not accepted by Customs. An example of an acceptable description is Office Correspondence. Clearance delays may result if the contents are not completely and accurately described. |
| Document | Document is an incomplete description and not accepted by Customs. An example of an acceptable description is Birth Certificate. Clearance delays may result if the contents are not completely and accurately described. |
| Documentation | Documentation is an incomplete description and not accepted by Customs. An example of an acceptable description is Office Correspondence. Clearance delays may result if the contents are not completely and accurately described. |
| Documents | Documents is an incomplete description and not accepted by Customs. An example of an acceptable description is Birth Certificate. Clearance delays may result if the contents are not completely and accurately described. |
| Drug | Drug is an incomplete description and not accepted by Customs. Specify the name of the medication or product being shipped and its intended use. Clearance delays may result if the contents are not completely and accurately described. |
| Drugs | Drugs is an incomplete description and not accepted by Customs. Specify the name of the medication or product being shipped and its intended use. Clearance delays may result if the contents are not completely and accurately described. |
| Dry Ice | Dry Ice is an incomplete description and not accepted by Customs. An example of an acceptable description is Pork Ribs in Dry Ice. Clearance delays may result if the contents are not completely and accurately described. |
| DVD | DVD is an incomplete description and not accepted by Customs. An example of an acceptable description is DVD Player. Clearance delays may result if the contents are not completely and accurately described. |
| DVDs | DVDs is an incomplete description and not accepted by Customs. An example of an acceptable description is DVD Movies. Clearance delays may result if the contents are not completely and accurately described. |
| Electrical Parts | Electrical Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Transistor. Clearance delays may result if the contents are not completely and accurately described. |
| Electronic Component | Electronic Component is an incomplete description and not accepted by Customs. An example of an acceptable description is Transistor. Clearance delays may result if the contents are not completely and accurately described. |
| Electronic Components | Electronic Components is an incomplete description and not accepted by Customs. An example of an acceptable description is Capacitor. Clearance delays may result if the contents are not completely and accurately described. |
| Electronic Equipment | Electronic Equipment is an incomplete description and not accepted by Customs. An example of an acceptable description is DVD Player. Clearance delays may result if the contents are not completely and accurately described. |
| Electronic Good | Electronic Good is an incomplete description and not accepted by Customs. An example of an acceptable description is DVD Players. Clearance delays may result if the contents are not completely and accurately described. |
| Electronic Goods | Electronic Goods is an incomplete description and not accepted by Customs. An example of an acceptable description is DVD Players. Clearance delays may result if the contents are not completely and accurately described. |
| Electronic Part | Electronic Part is an incomplete description and not accepted by Customs. An example of an acceptable description is Transistor. Clearance delays may result if the contents are not completely and accurately described. |
| Electronic Parts | Electronic Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Transistors. Clearance delays may result if the contents are not completely and accurately described. |
| Electronic | Electronic is an incomplete description and not accepted by Customs. An example of an acceptable description is DVD Player. Clearance delays may result if the contents are not completely and accurately described. |
| Electronics | Electronics is an incomplete description and not accepted by Customs. An example of an acceptable description is DVD Player. Clearance delays may result if the contents are not completely and accurately described. |
| Equipment | Equipment is an incomplete description and not accepted by Customs. Specific Description of the Type of equipment and its intended use is Required Clearance delays may result if the contents are not completely and accurately described. |
| Fabric Samples | Fabric Samples is an incomplete description and not accepted by Customs. An example of an acceptable description is Women's Fabric for Clothing - 100% Cotton. Clearance delays may result if the contents are not completely and accurately described. |
| Fabric | Fabric is an incomplete description and not accepted by Customs. An example of an acceptable description is Women's Fabric for Clothing - 100% Cotton. Clearance delays may result if the contents are not completely and accurately described. |
| Fabrics | Fabrics is an incomplete description and not accepted by Customs. An example of an acceptable description is Women's Fabric for Clothing - 100% Cotton. Clearance delays may result if the contents are not completely and accurately described. |
| FAC | FAC is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| FAK | FAK is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Flooring | Flooring is an incomplete description and not accepted by Customs. An example of an acceptable description is Ceramic Tiles. Clearance delays may result if the contents are not completely and accurately described. |
| Food Items | Food Items is an incomplete description and not accepted by Customs. An example of an acceptable description is Canned Pasta. Clearance delays may result if the contents are not completely and accurately described. |
| Food | Food is an incomplete description and not accepted by Customs. An example of an acceptable description is Homemade Cookies. Clearance delays may result if the contents are not completely and accurately described. |
| Foodstuff | Foodstuff is an incomplete description and not accepted by Customs. An example of an acceptable description is Chocolate Bars. Clearance delays may result if the contents are not completely and accurately described. |
| Foodstuffs | Foodstuffs is an incomplete description and not accepted by Customs. An example of an acceptable description is Chocolate Bars. Clearance delays may result if the contents are not completely and accurately described. |
| General Cargo | General Cargo is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Gift | Gift is an incomplete description and not accepted by Customs. An example of an acceptable description is Book sent as a Christmas Gift. Clearance delays may result if the contents are not completely and accurately described. |
| Gifts | Gifts is an incomplete description and not accepted by Customs. An example of an acceptable description is Books sent as a Christmas Gift. Clearance delays may result if the contents are not completely and accurately described. |
| Goods | Goods is an incomplete description and not accepted by Customs. An example of an acceptable description is Personal Effects. Clearance delays may result if the contents are not completely and accurately described. |
| Hardware | Hardware is an incomplete description and not accepted by Customs. An example of an acceptable description is CD Player. Clearance delays may result if the contents are not completely and accurately described. |
| Haz Mat | Haz Mat is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Haz Material | Haz Material is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Haz Materials | Haz Materials is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Hazardous Chemical | Hazardous Chemical Materials is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Hazardous Chemicals | Hazardous Chemicals Materials is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Hazardous Good | Hazardous Good is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Hazardous Goods | Hazardous Goods is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Hazardous Material | Hazardous Material is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Hazardous Materials | Hazardous Materials is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| HAZMAT | HazMat is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Household Goods | Household Goods is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| HS # | HS # is an incomplete description and not accepted by Customs. Please provide the full digit Harmonized Code if available and the description of the product. Clearance delays may result if the contents are not completely and accurately described. |
| HS NON | HS NON is an incomplete description and not accepted by Customs. Please provide the full digit Harmonized Code if available and the description of the product. Clearance delays may result if the contents are not completely and accurately described. |
| HS# | HS# is an incomplete description and not accepted by Customs. Please provide the full digit Harmonized Code if available and the description of the product. Clearance delays may result if the contents are not completely and accurately described. |
| I C | I C is an incomplete description and not accepted by Customs. An example of an acceptable description is Integrated Circuits - EEPROM. Clearance delays may result if the contents are not completely and accurately described. |
| IC | IC is an incomplete description and not accepted by Customs. An example of an acceptable description is Integrated Circuits - EEPROM. Clearance delays may result if the contents are not completely and accurately described. |
| ILLEDG | ILLEDG is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Illegible | Illegible is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Implants | Implants is an incomplete description and not accepted by Customs. An example of an acceptable description is Dental Implants. Clearance delays may result if the contents are not completely and accurately described. |
| Industrial Goods | Industrial Goods is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Integrated Circuit | Integrated Circuit is an incomplete description and not accepted by Customs. An example of an acceptable description is Integrated Circuit - EEPROM. Clearance delays may result if the contents are not completely and accurately described. |
| Integrated Circuits | Integrated Circuits is an incomplete description and not accepted by Customs. An example of an acceptable description is Integrated Circuits - EEPROM. Clearance delays may result if the contents are not completely and accurately described. |
| Iron | Iron is an incomplete description and not accepted by Customs. An example of an acceptable description is Steam Iron. Clearance delays may result if the contents are not completely and accurately described. |
| Items | Items is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Jeans | Jeans is an incomplete description and not accepted by Customs. An example of an acceptable description is Ladies Denim Jeans. Clearance delays may result if the contents are not completely and accurately described. |
| Jewelry | Jewelry is an incomplete description and not accepted by Customs. An example of an acceptable description is 18 Carat Gold Necklace. Clearance delays may result if the contents are not completely and accurately described. |
| Laboratory Reagents | Laboratory Reagents is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Ladies Apparel | Ladies Apparel is an incomplete description and not accepted by Customs. An example of an acceptable description is Women's leather shoes. Clearance delays may result if the contents are not completely and accurately described. |
| Leather Article | Leather Article is an incomplete description and not accepted by Customs. An example of an acceptable description is Leather Purse. Clearance delays may result if the contents are not completely and accurately described. |
| Leather Articles | Leather Articles is an incomplete description and not accepted by Customs. An example of an acceptable description is Leather Purse. Clearance delays may result if the contents are not completely and accurately described. |
| Leather | Leather is an incomplete description and not accepted by Customs. An example of an acceptable description is Leather Belts. Clearance delays may result if the contents are not completely and accurately described. |
| Letter | Letter is an incomplete description and not accepted by Customs. An example of an acceptable description is Personal Correspondence. Clearance delays may result if the contents are not completely and accurately described. |
| Liquid | Liquid is an incomplete description and not accepted by Customs. Please provide the actual chemical or product name and the UN HAZMAT #. Clearance delays may result if the contents are not completely and accurately described. |
| Luggage | Luggage is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Machine Part | Machine Part is an incomplete description and not accepted by Customs. An example of an acceptable description is Remanufactured Alternator for a Farm Tractor. Clearance delays may result if the contents are not completely and accurately described. |
| Machine Parts | Machine Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Remanufactured Alternator for a Farm Tractor. Clearance delays may result if the contents are not completely and accurately described. |
| Machinery | Machinery is an incomplete description and not accepted by Customs. An example of an acceptable description is Wood Cutting Machine. Clearance delays may result if the contents are not completely and accurately described. |
| Machines | Machines is an incomplete description and not accepted by Customs. An example of an acceptable description is Wood Cutting Machine. Clearance delays may result if the contents are not completely and accurately described. |
| Medical Equipment | Medical Equipment is an incomplete description and not accepted by Customs. An example of an acceptable description is Defibrillator. Clearance delays may result if the contents are not completely and accurately described. |
| Medical Parts | Medical Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Electric Wire for Medical Equipment. Clearance delays may result if the contents are not completely and accurately described. |
| Medical Spare Parts | Medical Spare Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Electric Wire for Medical Equipment. Clearance delays may result if the contents are not completely and accurately described. |
| Medical Supplies | Medical Supplies is an incomplete description and not accepted by Customs. Please provide the specific name of the medication or product being shipped and its intended use. Clearance delays may result if the contents are not completely and accurately described. |
| Medicaments | Medicaments is an incomplete description and not accepted by Customs. Please provide the specific name of the medication or product being shipped and its intended use. Clearance delays may result if the contents are not completely and accurately described. |
| Medication | Medication is an incomplete description and not accepted by Customs. Please provide the specific name of the medication or product being shipped and its intended use. Clearance delays may result if the contents are not completely and accurately described. |
| Medications | Medications is an incomplete description and not accepted by Customs. Please provide the specific name of the medication or product being shipped and its intended use. Clearance delays may result if the contents are not completely and accurately described. |
| Medicine | Medicine is an incomplete description and not accepted by Customs. Please provide the specific name of the medication or product being shipped and its intended use. Clearance delays may result if the contents are not completely and accurately described. |
| Medicines | Medicines is an incomplete description and not accepted by Customs. Please provide the specific name of the medication or product being shipped and its intended use. Clearance delays may result if the contents are not completely and accurately described. |
| Meds | Meds is an incomplete description and not accepted by Customs. Please provide the specific name of the medication or product being shipped and its intended use. Clearance delays may result if the contents are not completely and accurately described. |
| Men’s Apparel | Men’s Apparel is an incomplete description and not accepted by Customs. An example of an acceptable description is Men's Summer T-Shirt. Clearance delays may result if the contents are not completely and accurately described. |
| Metal Work | Metal Work is an incomplete description and not accepted by Customs. An example of an acceptable description is Copper Pipe. Clearance delays may result if the contents are not completely and accurately described. |
| Miscellaneous Items | Miscellaneous Items is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| NAFTA | NAFTA is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| New Goods | New Goods is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| No CI | No CI is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| NO COM | NO COM is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| NO DES | NO DES is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| NON G | NON G is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Non-Hazardous | Non-Hazardous is an incomplete description and not accepted by Customs. Please provide the proper name of the goods. |
| NOT GI | NOT GI is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Packaging Supplies | Packaging Supplies is an incomplete description and not accepted by Customs. An example of an acceptable description is Bubble Plastic Wrap. Clearance delays may result if the contents are not completely and accurately described. |
| Pants | Pants is an incomplete description and not accepted by Customs. An example of an acceptable description is Boy's Cotton Twill Pants. Clearance delays may result if the contents are not completely and accurately described. |
| Paper | Paper is an incomplete description and not accepted by Customs. An example of an acceptable description is Legal Contract. Clearance delays may result if the contents are not completely and accurately described. |
| Paperwork | Paperwork is an incomplete description and not accepted by Customs. An example of an acceptable description is Legal Contract. Clearance delays may result if the contents are not completely and accurately described. |
| Part | Part is an incomplete description and not accepted by Customs. An example of an acceptable description is Brake Caliper for Aircraft. Clearance delays may result if the contents are not completely and accurately described. |
| Parts Of | Parts Of is an incomplete description and not accepted by Customs. An example of an acceptable description is Brake Calipers for Aircraft. Clearance delays may result if the contents are not completely and accurately described. |
| Parts | Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Brake Calipers for Aircraft. Clearance delays may result if the contents are not completely and accurately described. |
| PC Hardware | PC Hardware is an incomplete description and not accepted by Customs. An example of an acceptable description is Computer CD Player. Clearance delays may result if the contents are not completely and accurately described. |
| PCB | PCB is an incomplete description and not accepted by Customs. An example of an acceptable description is Printed Circuit Board with Components for Television Set. Clearance delays may result if the contents are not completely and accurately described. |
| PCBA | PCBA is an incomplete description and not accepted by Customs. An example of an acceptable description is Printed Circuit Board Assembly for Computer. Clearance delays may result if the contents are not completely and accurately described. |
| Peripheral | Peripheral is an incomplete description and not accepted by Customs. An example of an acceptable description is Computer Printer. Clearance delays may result if the contents are not completely and accurately described. |
| Personal Effects | Personal Effects is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Personal Item | Personal Item is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Personal Items | Personal Items is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Pharmaceuticals | Pharmaceuticals is an incomplete description and not accepted by Customs. Please provide the specific name of the medication or product being shipped and its intended use. Clearance delays may result if the contents are not completely and accurately described. |
| PIB | PIB is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| PIBs | PIBs is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Pipe | Pipe is an incomplete description and not accepted by Customs. An example of an acceptable description is Steel Pipe. Clearance delays may result if the contents are not completely and accurately described. |
| Pipes | Pipes is an incomplete description and not accepted by Customs. An example of an acceptable description is Steel Pipes. Clearance delays may result if the contents are not completely and accurately described. |
| Plastic Good | Plastic Good is an incomplete description and not accepted by Customs. An example of an acceptable description is Plastic Knife. Clearance delays may result if the contents are not completely and accurately described. |
| Plastic Goods | Plastic Goods is an incomplete description and not accepted by Customs. An example of an acceptable description is Plastic Knives. Clearance delays may result if the contents are not completely and accurately described. |
| Plastic Parts | Plastic Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Plastic Knives. Clearance delays may result if the contents are not completely and accurately described. |
| Plastic | Plastic is an incomplete description and not accepted by Customs. An example of an acceptable description is Women's Plastic Shoes. Clearance delays may result if the contents are not completely and accurately described. |
| Polyurethane | Polyurethane is an incomplete description and not accepted by Customs. An example of an acceptable description is Polyurethane Medical Gloves. Clearance delays may result if the contents are not completely and accurately described. |
| Power Supply | Power Supply is an incomplete description and not accepted by Customs. An example of an acceptable description is Power Supply Module for ADP Machines. Clearance delays may result if the contents are not completely and accurately described. |
| Precious Metal | Precious Metal is an incomplete description and not accepted by Customs. An example of an acceptable description is 18 Carat Gold Necklace. Clearance delays may result if the contents are not completely and accurately described. |
| Printed Circuit Board | Printed Circuit Board is an incomplete description and not accepted by Customs. An example of an acceptable description is Printed Circuit Board with Components for Television Set. Clearance delays may result if the contents are not completely and accurately described. |
| Printed Material | Printed Material is an incomplete description and not accepted by Customs. An example of an acceptable description is TV Owner's Manual. Clearance delays may result if the contents are not completely and accurately described. |
| Printed Materials | Printed Materials is an incomplete description and not accepted by Customs. An example of an acceptable description is TV Owner's Manuals. Clearance delays may result if the contents are not completely and accurately described. |
| Printed Matter | Printed Matter is an incomplete description and not accepted by Customs. An example of an acceptable description is TV Owner's Manual Clearance delays may result if the contents are not completely and accurately described. |
| Printed Matters | Printed Matters is an incomplete description and not accepted by Customs. An example of an acceptable description is TV Owner's Manuals Clearance delays may result if the contents are not completely and accurately described. |
| Promo Item | Promo Item is an incomplete description and not accepted by Customs. An example of an acceptable description is Promotional Inflatable Balloons not for resale. Clearance delays may result if the contents are not completely and accurately described. |
| Promo Items | Promo Items is an incomplete description and not accepted by Customs. An example of an acceptable description is Promotional Inflatable Balloons not for resale. Clearance delays may result if the contents are not completely and accurately described. |
| Promo Material | Promo Material is an incomplete description and not accepted by Customs. An example of an acceptable description is Promotional Inflatable Balloons not for resale. Clearance delays may result if the contents are not completely and accurately described. |
| Promo Materials | Promo Materials is an incomplete description and not accepted by Customs. An example of an acceptable description is Promotional Inflatable Balloons not for resale. Clearance delays may result if the contents are not completely and accurately described. |
| Promotional Item | Promotional Item is an incomplete description and not accepted by Customs. An example of an acceptable description is Promotional Inflatable Balloons not for resale. Clearance delays may result if the contents are not completely and accurately described. |
| Promotional Items | Promotional Items is an incomplete description and not accepted by Customs. An example of an acceptable description is Promotional Inflatable Balloons not for resale. Clearance delays may result if the contents are not completely and accurately described. |
| Promotional Material | Promotional Material is an incomplete description and not accepted by Customs. An example of an acceptable description is Promotional Inflatable Balloons not for resale. Clearance delays may result if the contents are not completely and accurately described. |
| Promotional Materials | Promotional Materials is an incomplete description and not accepted by Customs. An example of an acceptable description is Promotional Inflatable Balloons not for resale. Clearance delays may result if the contents are not completely and accurately described. |
| Promotional | Promotional is an incomplete description and not accepted by Customs. An example of an acceptable description is Promotional Inflatable Balloons not for resale. Clearance delays may result if the contents are not completely and accurately described. |
| Receivers | Receivers is an incomplete description and not accepted by Customs. An example of an acceptable description is Stereo Receiver. Clearance delays may result if the contents are not completely and accurately described. |
| Records | Records is an incomplete description and not accepted by Customs. An example of an acceptable description is Office Correspondence. Clearance delays may result if the contents are not completely and accurately described. |
| Report | Report is an incomplete description and not accepted by Customs. An example of an acceptable description is Business Correspondence - Annual Report. Clearance delays may result if the contents are not completely and accurately described. |
| Rod | Rod is an incomplete description and not accepted by Customs. An example of an acceptable description is Fishing Rod. Clearance delays may result if the contents are not completely and accurately described. |
| Rods | Rods is an incomplete description and not accepted by Customs. An example of an acceptable description is Aluminum Rods. Clearance delays may result if the contents are not completely and accurately described. |
| Rubber Articles | Rubber Articles is an incomplete description and not accepted by Customs. An example of an acceptable description is Rubber Hoses. Clearance delays may result if the contents are not completely and accurately described. |
| Rubber | Rubber is an incomplete description and not accepted by Customs. An example of an acceptable description is Rubber Tires. Clearance delays may result if the contents are not completely and accurately described. |
| Said To Contain | Said To Contain is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Sample | Sample is an incomplete description and not accepted by Customs. An example of an acceptable description is Plastic Bags - SAMPLE. Clearance delays may result if the contents are not completely and accurately described. |
| Samples | Samples is an incomplete description and not accepted by Customs. An example of an acceptable description is Plastic Bags - SAMPLE. Clearance delays may result if the contents are not completely and accurately described. |
| Scrap | Scrap is an incomplete description and not accepted by Customs. An example of an acceptable description is Steel Scrap Billets. Clearance delays may result if the contents are not completely and accurately described. |
| See Attached | See Attached is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| SEE CO | SEE CO is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| SEE IN | SEE IN is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| See Invoice | See Invoice is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Shirt | Shirt is an incomplete description and not accepted by Customs. An example of an acceptable description is Men's 100% Cotton Long Sleeve Shirt. Clearance delays may result if the contents are not completely and accurately described. |
| Software | Software is an incomplete description and not accepted by Customs. An example of an acceptable description is Software Game on CD-ROM - Halo 2. Clearance delays may result if the contents are not completely and accurately described. |
| Spare Parts for Machine | Spare Parts for Machine is an incomplete description and not accepted by Customs. An example of an acceptable description is Alternator - New. Clearance delays may result if the contents are not completely and accurately described. |
| Spare Parts | Spare Parts is an incomplete description and not accepted by Customs. An example of an acceptable description is Alternator - Used. Clearance delays may result if the contents are not completely and accurately described. |
| Spares | Spares is an incomplete description and not accepted by Customs. An example of an acceptable description is Alternator - New. Clearance delays may result if the contents are not completely and accurately described. |
| Sportswear | Sportswear is an incomplete description and not accepted by Customs. An example of an acceptable description is 100% Cotton Men's Running Shorts. Clearance delays may result if the contents are not completely and accurately described. |
| STC | STC is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Steel | Steel is an incomplete description and not accepted by Customs. An example of an acceptable description is Stainless Steel Pots. Clearance delays may result if the contents are not completely and accurately described. |
| Surgical Instruments | Surgical Equipment is an incomplete description and not accepted by Customs. An example of an acceptable description is Scalpels. Clearance delays may result if the contents are not completely and accurately described. |
| Swatches | Swatches is an incomplete description and not accepted by Customs. An example of an acceptable description is 100% Cotton Fabric Sample Swatches. Clearance delays may result if the contents are not completely and accurately described. |
| Tape | Tape is an incomplete description and not accepted by Customs. An example of an acceptable description is Jazz Music Video Tape. Clearance delays may result if the contents are not completely and accurately described. |
| Tapes | Tapes is an incomplete description and not accepted by Customs. An example of an acceptable description is Jazz Music Video Tapes. Clearance delays may result if the contents are not completely and accurately described. |
| Textile Samples | Textile Samples is an incomplete description and not accepted by Customs. An example of an acceptable description is Men's Shirt 100% Cotton - SAMPLE. Clearance delays may result if the contents are not completely and accurately described. |
| Textile | Textile is an incomplete description and not accepted by Customs. An example of an acceptable description is Women's Dress - 100% Cotton - SAMPLE. Clearance delays may result if the contents are not completely and accurately described. |
| Textiles Samples | Textiles Samples is an incomplete description and not accepted by Customs. An example of an acceptable description is Men's Shirt 100% Cotton - SAMPLE. Clearance delays may result if the contents are not completely and accurately described. |
| Textiles | Textiles is an incomplete description and not accepted by Customs. An example of an acceptable description is Men's Shirt 100% Cotton - SAMPLE. Clearance delays may result if the contents are not completely and accurately described. |
| Tile | Tile is an incomplete description and not accepted by Customs. An example of an acceptable description is Ceramic Tiles. Clearance delays may result if the contents are not completely and accurately described. |
| Tiles | Tiles is an incomplete description and not accepted by Customs. An example of an acceptable description is Ceramic Tiles. Clearance delays may result if the contents are not completely and accurately described. |
| Tools | Tools is an incomplete description and not accepted by Customs. An example of an acceptable description is Power Drill. Clearance delays may result if the contents are not completely and accurately described. |
| Toy | Toy is an incomplete description and not accepted by Customs. An example of an acceptable description is Plastic Doll House. Clearance delays may result if the contents are not completely and accurately described. |
| Training Material | Training Material is an incomplete description and not accepted by Customs. An example of an acceptable description is Training Material for Basketball. Clearance delays may result if the contents are not completely and accurately described. |
| Training Materials | Training Materials is an incomplete description and not accepted by Customs. An example of an acceptable description is Training Materials for Basketball. Clearance delays may result if the contents are not completely and accurately described. |
| Tubes | Tubes is an incomplete description and not accepted by Customs. An example of an acceptable description is Glass Tubes. Clearance delays may result if the contents are not completely and accurately described. |
| Unlist | Unlist is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Used Goods | Used Goods is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Various Goods | Various Goods is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Video Tape | Video Tape is an incomplete description and not accepted by Customs. An example of an acceptable description is Jazz Music Video Tape. Clearance delays may result if the contents are not completely and accurately described. |
| Video Tapes | Video Tapes is an incomplete description and not accepted by Customs. An example of an acceptable description is Jazz Music Video Tapes. Clearance delays may result if the contents are not completely and accurately described. |
| Video | Video is an incomplete description and not accepted by Customs. An example of an acceptable description is Jazz Music Video Tapes. Clearance delays may result if the contents are not completely and accurately described. |
| Videotape | Videotape is an incomplete description and not accepted by Customs. An example of an acceptable description is Jazz Music Video Tape. Clearance delays may result if the contents are not completely and accurately described. |
| Videotapes | Videotapes is an incomplete description and not accepted by Customs. An example of an acceptable description is Jazz Music Video Tapes. Clearance delays may result if the contents are not completely and accurately described. |
| VISA MDR Table | VISA MDR Table is an incomplete description and not accepted by Customs. Specify the description of the contents being shipped. Clearance delays may result if the contents are not completely and accurately described. |
| Wafer | Wafer is an incomplete description and not accepted by Customs. An example of an acceptable description is semiconductor wafers. Clearance delays may result if the contents are not completely and accurately described. |
| Waste | Waste is an incomplete description and not accepted by Customs. An example of an acceptable description is Oil Waste for Testing. Clearance delays may result if the contents are not completely and accurately described. |
| Wearing Apparel | Wearing Apparel is an incomplete description and not accepted by Customs. An example of an acceptable description is Women's Leather Sandals. Clearance delays may result if the contents are not completely and accurately described. |
| Wire | Wire is an incomplete description and not accepted by Customs. An example of an acceptable description is Insulated Copper Wire. Clearance delays may result if the contents are not completely and accurately described. |
| Wires | Wires is an incomplete description and not accepted by Customs. An example of an acceptable description is Insulated Copper Wire. Clearance delays may result if the contents are not completely and accurately described. |

### Shipping Documents

| TITLE | CREATION TIME | PRINT FORMATS | EXPORT TO DIRECTORY | MULTIPLE COPIES | PAPER SIZES |
| --- | --- | --- | --- | --- | --- |
| Commercial Invoice | ShipTime | RTF, PDF, DOC, TXT | Yes | Yes | 8-1/2' x 11' |
| Certificate of Origin | ShipTime | RTF, PDF, DOC, TXT | Yes | No | 8-1/2' x 11, A4' |
| FedEx Ground Pickup Manifest | Close | RTF, PDF, DOC | Yes | No | 8-1/2' x 11' |
| FedEx Ground NAFTA COO | ShipTime | RTF, PDF, DOC, TXT | Yes | No | 8-1/2' x 11, A4' |
| Ground HazMat OP-900 document | ShipTime | RTF, PDF, DOC | Yes | Yes | 8-1/2' x 11' |
| FedEx Ground OP-950 | ShipTime | RTF, PDF, DOC,TXT | Yes | No | 8-1/2' x 11', A4 |
| Pro Forma Invoice | ShipTime | RTF, PDF, DOC,TXT | Yes | Yes | 8-1/2' x 11, A4' |

### Shipment Document Type

| DESCRIPTION | ENUMERATION |
| --- | --- |
| European Union Antique Statement | ANTIQUE_STATEMENT_EUROPEAN_UNION |
| United States Antique Statement | ANTIQUE_STATEMENT_UNITED_STATES |
| Assembler Declaration | ASSEMBLER_DECLARATION |
| Bearing Worksheet | BEARING_WORKSHEET |
| Certificate of Shipments to Syria | CERTIFICATE_OF_SHIPMENTS_TO_SYRIA |
| Commercial Invoice for the Caribbean Common Market | COMMERCIAL_INVOICE_FOR_THE_CARIBBEAN_COMMON_MARKET |
| Coniferous Solid Wood Packaging Material to the Peoples Republic of China | CONIFEROUS_SOLID_WOOD_PACKAGING_MATERIAL_TO_THE_PEOPLES_REPUBLIC_OF_CHINA |
| Declaration for Free Entry of Returned American Products | DECLARATION_FOR_FREE_ENTRY_OF_RETURNED_AMERICAN_PRODUCTS |
| Declaration Of Biological Standards | DECLARATION_OF_BIOLOGICAL_STANDARDS |
| Declaration of Imported Electronic Products Subject to Radiation Control Standard | DECLARATION_OF_IMPORTED_ELECTRONIC_PRODUCTS_SUBJECT_TO_RADIATION_CONTROL_STANDARD |
| Electronic Integrated Circuit Worksheet | ELECTRONIC_INTEGRATED_CIRCUIT_WORKSHEET |
| Film and Video Certificate | FILM_AND_VIDEO_CERTIFICATE |
| Interim Footwear Invoice | INTERIM_FOOTWEAR_INVOICE |
| {{USMCA}} Commercial Invoice Certification of Origin English | NAFTA_CERTIFICATE_OF_ORIGIN_CANADA_ENGLISH |
| {{USMCA}} Commercial Invoice Certification of Origin French | NAFTA_CERTIFICATE_OF_ORIGIN_CANADA_FRENCH |
| {{USMCA}} Commercial Invoice Certification of Origin Spanish | NAFTA_CERTIFICATE_OF_ORIGIN_SPANISH |
| Packing List | PACKING_LIST |
| Printed Circuit Board Worksheet | PRINTED_CIRCUIT_BOARD_WORKSHEET |
| Repaired Watch Breakout Worksheet | REPAIRED_WATCH_BREAKOUT_WORKSHEET |
| Statement Regarding the Import of Radio Frequency Devices | STATEMENT_REGARDING_THE_IMPORT_OF_RADIO_FREQUENCY_DEVICES |
| Toxic Substances Control Act | TOXIC_SUBSTANCES_CONTROL_ACT |
| United States Caribbean Basin Trade Partnership Act Certificate of Origin Non Textiles | UNITED_STATES_CARIBBEAN_BASIN_TRADE_PARTNERSHIP_ACT_CERTIFICATE_OF_ORIGIN_NON_TEXTILES |
| United States Caribbean Basin Trade Partnership Act Certificate of Origin Textiles | UNITED_STATES_CARIBBEAN_BASIN_TRADE_PARTNERSHIP_ACT_CERTIFICATE_OF_ORIGIN_TEXTILES |
| United States New Watch Worksheet | UNITED_STATES_NEW_WATCH_WORKSHEET |
| United States Watch Repair Declaration | UNITED_STATES_WATCH_REPAIR_DECLARATION |

### Address Attributes

| ATTRIBUTE NAMES | DESCRIPTION |
| --- | --- |
| BuildingValidated | Indicates if the Building was validated against reference data. |
| DPV | Indicates the presence of a Delivery Point such as a mailbox. DPV = Delivery Point Valid. Indicator translated from values provided by the USPS that identify the validity of a postal delivery address. Provided for US addresses only that can be standardized against Postal Data. Not provided for US Geo Validated addresses. |
| EncompassingZIP | TRUE indicates that the current address’ zip code encompasses other zip codes. FALSE indicates that the current address' zip code does not encompass other zip codes. (US only) |
| InterpolatedStreetAddress | TRUE indicates that the house number of the address is valid within a known range of street numbers, but that the existence of the specific street number could not be confirmed. This usually occurs when postal data can’t confirm the address and mapping data is used instead. The house number of the address is included within the matched range, but the reference data does not include the point level address data required to validate that the input street number actually exists within the matched range. |
| Intersection | TRUE indicates that the address is an intersection. FALSE indicates that the address is not an intersection. |
| InvalidSuitENUMERATIONber | TRUE indicates that the suite information was provided and was either incorrect, or was provided for an address that was not recognized as requiring secondary information. FALSE indicates that the suite information was not provided and was not needed, or provided suite information was valid. |
| MissingOrAmbiguousDirectional | Flag only returned when address is not resolved.  TRUE indicates that the address is missing a required leading or trailing directional. FALSE indicates that the address is NOT missing a required leading or trailing directional. |
| MultiUnitBase | TRUE indicates that an input address was resolved to a standardized address for the base address of a multi-unit building. FALSE indicates that the address was not resolved to a standardized address for the base address of a multi-unit building. |
| OrganizationValidated | Indicates if the Organization was validated against reference data. Value returned - NULL. |
| POBox | TRUE indicates that input address was recognized as a PO Box address. FALSE indicates that input address was not recognized as a PO Box address. |
| POBoxOnlyZIP | TRUE indicates that USPS considers this ZIP as a PO Box- only postal code. This means that USPS does not deliver to individual street addresses in the postal code. Valid street addresses may exist in the postal code, but they cannot be validated by the USPS reference data. FALSE indicates that the USPS does not consider this ZIP as a PO Box only postal code. (US only) |
| PostalValidated | Indicates if the PostalCode was validated against reference data. For US addresses, this is only returned when address cannot be standardized. Always returned for international addresses |
| RRConversion | Indicates if a Rural Route conversion was applied to the address during standardization. This flag applies to Canadian and International addresses only. There is a similar flag (standardized.status.name = RRConversion) associated with the standardized address that applies to US addresses.  TRUE indicates that the input address was recognized as a Rural Route or Highway Contract addresses and that it was matched to a standardized address through a onversion to a normal street address. FALSE indicates that the input address was not recognized as a Rural Route or Highway Contract address and was not converted to a street address. (US only.) |
| Resolved | Indicates if address can be standardized (resolved). |
| RuralRoute | TRUE indicates that the input address was recognized as a Rural Route or Highway Contract addresses. FALSE indicates that the input address was not recognized as a Rural Route or Highway Contract address. |
| SplitZIP | TRUE indicates when the address comes under a new ZIP code that did not previously exist. FALSE indicates when the address does not come under a new ZIP code that did not previously exist. |
| StreetAddress | TRUE indicates that the house number and street name were validated against reference data. FALSE indicates that the house number and street name were not validated against reference data. (Non-US addresses only, where applicable) |
| StreetBuildingAddress | TRUE indicates that the building and street information were validated against reference data, but not house number. FALSE indicates that the building and street information were not validated against reference data. (Non-US addresses only, where applicable) |
| StreetNameAddress | TRUE indicates that the street name was validated against reference data, but not house number. Note that house number may not be applicable for the address. FALSE indicates that the street name was not validated against reference data. (Non-US addresses only, where applicable) |
| StreetOrganizationAddress | TRUE indicates that organization and street information were validated against reference data. FALSE indicates that organization and street information were not validated against reference data. (Non-US addresses only, where applicable) |
| StreetPointNotApplicable | TRUE indicates that house number at the street level is not applicable for this address. FALSE indicates that the house number at the street level is applicable for this address. (Non-US addresses only, where applicable) |
| StreetPointNotValidated | TRUE indicates that the house number for the street address was not validated against reference data. FALSE indicates that the house number for the street address was either not validated, not provided, or not relevant for the address. (Non-US addresses only, where applicable) |
| StreetRange | TRUE indicates that the address includes a street number range instead of a single house number. The range is from the input address from which this address was resolved, and that the input range was validated as being included within a known street range segment for the matched street. FALSE indicates that the address does not include a street number range. (Non-US addresses only, where applicable) |
| StreetRangeValidated | TRUE indicates that the house number and street were validated against a range of house numbers for that street provided in the reference data. FALSE indicates that the house number and street were not validated. |
| StreetValidated | Returned for Canada and Generic Resolver. |
| SuiteNotValidated | TRUE indicates: input address contains suite information. reference data is available and has confirmed that this address is a base building. reference data is not available to validate the suite information. FALSE indicates either: Suite information was not provided as input. Suite information was provided and reference data is available to validate the suite information. |
| SuiteRequiredButMissing | TRUE indicates that an input address was resolved to a base building address and that a suite or unit number is required to achieve a more exact match, but this secondary address information is missing from the input address. FALSE indicates that a suite was either not needed and not provided, or was provided and was valid. |
| ValidMultiUnit | TRUE indicates that the address includes a validated suite or unit number. FALSE indicates that the address does not include a validated suite or unit number. |
| Zip4Match | TRUE indicates that the input address was resolved to a standardized address based upon at least a ZIP+4 match. FALSE indicates that the address was not resolved to a standardized address based upon at least ZIP+4 match. (US only) |
| Zip11Match | TRUE indicates that the input address was resolved to a standardized address based upon a match at the postal barcode level (i.e. Zip-11 match). This is the highest level of postal code validation. All addresses resolved with the ZIP- 11 Match flag set will also have the ZIP-4 Match flag set. FALSE indicates that the input address was not resolved to a standardized address based upon Zip 11match. (US Only) |

