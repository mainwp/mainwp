<?php
/**
 * MainWP Module Cost Tracker Utility class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_UI;

/**
 * Class Cost_Tracker_Utility
 */
class Cost_Tracker_Utility {

    /**
     * Static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Variable to hold the option name.
     *
     * @var string Default option name.
     */
    protected $option_handle = 'mainwp_module_cost_tracker_options';

    /**
     * Variable to hold the options.
     *
     * @var mixed Options.
     */
    protected $option;

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @static
     *
     * @return Cost_Tracker_Utility
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor
     *
     * Runs each time the class is called.
     */
    public function __construct() {
        $this->option = get_option( $this->option_handle );
        if ( ! is_array( $this->option ) ) {
            $this->option = array();
        }
    }

    /**
     * Get Option
     *
     * Gets option in Databse.
     *
     * @param string $key Option key.
     * @param mixed  $defval Default value.
     * @param bool   $json_encoded Is json encoded value.
     *
     * @return mixed Retruns option value.
     */
    public function get_option( $key = null, $defval = '', $json_encoded = false ) {
        if ( isset( $this->option[ $key ] ) ) {
            $values = $this->option[ $key ];
            if ( $json_encoded ) {
                $values = ! empty( $values ) ? json_decode( $values, true ) : array();
                if ( ! is_array( $values ) ) {
                    $values = array();
                }
            }
            return $values;
        }
        return $defval;
    }

    /**
     * Set Option
     *
     * Sets option in Databse.
     *
     * @param string $key Option key.
     * @param mixed  $value Option value.
     *
     * @return mixed Update option.
     */
    public function set_option( $key, $value ) {
        $this->option[ $key ] = $value;
        return update_option( $this->option_handle, $this->option );
    }

    /**
     * Get All Options
     *
     * Gets options in Databse.
     *
     * @return mixed Retruns options value.
     */
    public function get_all_options() {
        return $this->option;
    }

    /**
     * Save All Options
     *
     * @param array $options Options.
     *
     * @return mixed Retruns value.
     */
    public function save_options( $options ) {
        return update_option( $this->option_handle, $options );
    }

    /**
     * Method render_product_icon().
     *
     * @param  string $file_name File icon.
     * @param  bool   $ret True|False To return.
     *
     * @return string
     */
    public static function render_product_icon( $file_name, $ret = false ) {

        $imgfavi = '';

        $favi_url = MainWP_Utility::get_saved_favicon_url( $file_name );
        if ( ! empty( $favi_url ) ) {
            $imgfavi = '<img src="' . esc_attr( $favi_url ) . '" style="width:28px;height:28px;" class="ui circular image" alt="Cost custom icon" />';
        }

        if ( $ret ) {
            return $imgfavi;
        }
        echo $imgfavi;//phpcs:ignore -- ok.
    }

    /**
     * Format the price with a currency symbol.
     *
     * @param  float $price Raw price.
     * @param  bool  $ret Return or echo value.
     * @param  array $params other params.
     *
     * @return string
     */
    public static function cost_tracker_format_price( $price, $ret = false, $params = array() ) { //phpcs:ignore -- NOSONAR - complex.
        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $get_currency_format = ! empty( $params['get_currency_format'] ) ? true : false;
        $get_formated_number = ! empty( $params['get_formated_number'] ) ? true : false;
        $get_decimals        = ! empty( $params['get_decimals'] ) ? true : false;

        $currency = static::get_instance()->get_option( 'currency' );
        $settings = static::get_instance()->get_option( 'currency_format' );

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        $default = static::default_currency_settings();
        $args    = array_merge( $default, $settings );

        // Convert to float to avoid issues on PHP 8.
        $price = (float) $price;

        $negative = $price < 0;

        $price = $negative ? $price * -1 : $price;
        $price = number_format( $price, $args['decimals'], $args['decimal_separator'], $args['thousand_separator'] );

        if ( $get_decimals ) {
            return $args['decimals'];
        }

        if ( $get_formated_number ) {
            return $price;
        }

        if ( $args['decimals'] > 0 ) {
            // trim zezos.
            $price = preg_replace( '/' . preg_quote( $args['decimal_separator'], '/' ) . '0++$/', '', $price ?? '' );
        }

        $currency_pos = $args['currency_position'];

        $format = '%1$s%2$s';
        switch ( $currency_pos ) {
            case 'left':
                $format = '%1$s%2$s';
                break;
            case 'right':
                $format = '%2$s%1$s';
                break;
            case 'left_space':
                $format = '%1$s&nbsp;%2$s';
                break;
            case 'right_space':
                $format = '%2$s&nbsp;%1$s';
                break;
            default:
                break;
        }

        if ( $get_currency_format ) {
            return array(
                'format'   => sprintf( $format, esc_html( static::get_currency_symbol( $currency ) ), '%1' ), // %1 for price holder.
                'decimals' => $args['decimals'],
            );
        }

        $formatted_price = ( $negative ? '-' : '' ) . sprintf( $format, '<span class="cost-tracker-currency-symbol">' . esc_html( static::get_currency_symbol( $currency ) ) . '</span>', esc_html( $price ) );
        $value           = '<span class="cost-tracker-currency-amount"><bdi>' . $formatted_price . '</bdi></span>';

        if ( $ret ) {
            return $value;
        }
        echo $value; // phpcs:ignore -- escaped.
    }

    /**
     * Format the group costs prices.
     *
     * @param  array $group_prices Group price.
     *
     * @return string
     */
    public static function get_separated_costs_price( $group_prices ) {

        $grouped = array(
            'weekly'    => 'W',
            'monthly'   => 'M',
            'quarterly' => 'Q',
            'yearly'    => 'Y',
            'lifetime'  => 'L',
        );

        $formated_prices = array();
        if ( is_array( $group_prices ) ) {
            foreach ( $grouped as $idx => $char ) {
                if ( ! empty( $group_prices[ $idx ] ) ) {
                    $formated_prices[ $idx ] = static::cost_tracker_format_price( $group_prices[ $idx ], true ) . '/' . $char;
                }
            }
        }
        if ( ! empty( $formated_prices ) ) {
            return implode( '|', $formated_prices );
        }
        return static::cost_tracker_format_price( 0, true );
    }

    /**
     * Method get_currency_symbol().
     *
     * @param string $currency Currency.
     *
     * @return string Currency symbol.
     */
    public static function get_currency_symbol( $currency ) {
        if ( ! is_string( $currency ) ) {
            return '';
        }
        $all_curs = static::get_all_currency_symbols();
        return isset( $all_curs[ $currency ]['symbol'] ) ? $all_curs[ $currency ]['symbol'] : '';
    }

    /**
     * Method get_all_currency_symbols().
     *
     * @return array Currency symbols.
     */
    public static function get_all_currency_symbols() { // phpcs:ignore -- NOSONAR -  multi lines.

        return array(
            'AFA' => array(
                'name'   => 'Afghan Afghani',
                'symbol' => '؋',
            ),
            'ALL' => array(
                'name'   => 'Albanian Lek',
                'symbol' => 'Lek',
            ),
            'DZD' => array(
                'name'   => 'Algerian Dinar',
                'symbol' => 'دج',
            ),
            'AOA' => array(
                'name'   => 'Angolan Kwanza',
                'symbol' => 'Kz',
            ),
            'ARS' => array(
                'name'   => 'Argentine Peso',
                'symbol' => '$',
            ),
            'AMD' => array(
                'name'   => 'Armenian Dram',
                'symbol' => '֏',
            ),
            'AWG' => array(
                'name'   => 'Aruban Florin',
                'symbol' => 'ƒ',
            ),
            'AUD' => array(
                'name'   => 'Australian Dollar',
                'symbol' => '$',
            ),
            'AZN' => array(
                'name'   => 'Azerbaijani Manat',
                'symbol' => 'm',
            ),
            'BSD' => array(
                'name'   => 'Bahamian Dollar',
                'symbol' => 'B$',
            ),
            'BHD' => array(
                'name'   => 'Bahraini Dinar',
                'symbol' => '.د.ب',
            ),
            'BDT' => array(
                'name'   => 'Bangladeshi Taka',
                'symbol' => '৳',
            ),
            'BBD' => array(
                'name'   => 'Barbadian Dollar',
                'symbol' => 'Bds$',
            ),
            'BYR' => array(
                'name'   => 'Belarusian Ruble',
                'symbol' => 'Br',
            ),
            'BEF' => array(
                'name'   => 'Belgian Franc',
                'symbol' => 'fr',
            ),
            'BZD' => array(
                'name'   => 'Belize Dollar',
                'symbol' => '$',
            ),
            'BMD' => array(
                'name'   => 'Bermudan Dollar',
                'symbol' => '$',
            ),
            'BTN' => array(
                'name'   => 'Bhutanese Ngultrum',
                'symbol' => 'Nu.',
            ),
            'BTC' => array(
                'name'   => 'Bitcoin',
                'symbol' => '฿',
            ),
            'BOB' => array(
                'name'   => 'Bolivian Boliviano',
                'symbol' => 'Bs.',
            ),
            'BAM' => array(
                'name'   => 'Bosnia-Herzegovina Convertible Mark',
                'symbol' => 'KM',
            ),
            'BWP' => array(
                'name'   => 'Botswanan Pula',
                'symbol' => 'P',
            ),
            'BRL' => array(
                'name'   => 'Brazilian Real',
                'symbol' => 'R$',
            ),
            'GBP' => array(
                'name'   => 'British Pound Sterling',
                'symbol' => '£',
            ),
            'BND' => array(
                'name'   => 'Brunei Dollar',
                'symbol' => 'B$',
            ),
            'BGN' => array(
                'name'   => 'Bulgarian Lev',
                'symbol' => 'Лв.',
            ),
            'BIF' => array(
                'name'   => 'Burundian Franc',
                'symbol' => 'FBu',
            ),
            'KHR' => array(
                'name'   => 'Cambodian Riel',
                'symbol' => 'KHR',
            ),
            'CAD' => array(
                'name'   => 'Canadian Dollar',
                'symbol' => '$',
            ),
            'CVE' => array(
                'name'   => 'Cape Verdean Escudo',
                'symbol' => '$',
            ),
            'KYD' => array(
                'name'   => 'Cayman Islands Dollar',
                'symbol' => '$',
            ),
            'XOF' => array(
                'name'   => 'CFA Franc BCEAO',
                'symbol' => 'CFA',
            ),
            'XAF' => array(
                'name'   => 'CFA Franc BEAC',
                'symbol' => 'FCFA',
            ),
            'XPF' => array(
                'name'   => 'CFP Franc',
                'symbol' => '₣',
            ),
            'CLP' => array(
                'name'   => 'Chilean Peso',
                'symbol' => '$',
            ),
            'CLF' => array(
                'name'   => 'Chilean Unit of Account',
                'symbol' => 'CLF',
            ),
            'CNY' => array(
                'name'   => 'Chinese Yuan',
                'symbol' => '¥',
            ),
            'COP' => array(
                'name'   => 'Colombian Peso',
                'symbol' => '$',
            ),
            'KMF' => array(
                'name'   => 'Comorian Franc',
                'symbol' => 'CF',
            ),
            'CDF' => array(
                'name'   => 'Congolese Franc',
                'symbol' => 'FC',
            ),
            'CRC' => array(
                'name'   => 'Costa Rican Colón',
                'symbol' => '₡',
            ),
            'HRK' => array(
                'name'   => 'Croatian Kuna',
                'symbol' => 'kn',
            ),
            'CUC' => array(
                'name'   => 'Cuban Convertible Peso',
                'symbol' => '$, CUC',
            ),
            'CZK' => array(
                'name'   => 'Czech Republic Koruna',
                'symbol' => 'Kč',
            ),
            'DKK' => array(
                'name'   => 'Danish Krone',
                'symbol' => 'Kr.',
            ),
            'DJF' => array(
                'name'   => 'Djiboutian Franc',
                'symbol' => 'Fdj',
            ),
            'DOP' => array(
                'name'   => 'Dominican Peso',
                'symbol' => '$',
            ),
            'XCD' => array(
                'name'   => 'East Caribbean Dollar',
                'symbol' => '$',
            ),
            'EGP' => array(
                'name'   => 'Egyptian Pound',
                'symbol' => 'ج.م',
            ),
            'ERN' => array(
                'name'   => 'Eritrean Nakfa',
                'symbol' => 'Nfk',
            ),
            'EEK' => array(
                'name'   => 'Estonian Kroon',
                'symbol' => 'kr',
            ),
            'ETB' => array(
                'name'   => 'Ethiopian Birr',
                'symbol' => 'Nkf',
            ),
            'EUR' => array(
                'name'   => 'Euro',
                'symbol' => '€',
            ),
            'FKP' => array(
                'name'   => 'Falkland Islands Pound',
                'symbol' => '£',
            ),
            'FJD' => array(
                'name'   => 'Fijian Dollar',
                'symbol' => 'FJ$',
            ),
            'GMD' => array(
                'name'   => 'Gambian Dalasi',
                'symbol' => 'D',
            ),
            'GEL' => array(
                'name'   => 'Georgian Lari',
                'symbol' => 'ლ',
            ),
            'DEM' => array(
                'name'   => 'German Mark',
                'symbol' => 'DM',
            ),
            'GHS' => array(
                'name'   => 'Ghanaian Cedi',
                'symbol' => 'GH₵',
            ),
            'GIP' => array(
                'name'   => 'Gibraltar Pound',
                'symbol' => '£',
            ),
            'GRD' => array(
                'name'   => 'Greek Drachma',
                'symbol' => '₯, Δρχ, Δρ',
            ),
            'GTQ' => array(
                'name'   => 'Guatemalan Quetzal',
                'symbol' => 'Q',
            ),
            'GNF' => array(
                'name'   => 'Guinean Franc',
                'symbol' => 'FG',
            ),
            'GYD' => array(
                'name'   => 'Guyanaese Dollar',
                'symbol' => '$',
            ),
            'HTG' => array(
                'name'   => 'Haitian Gourde',
                'symbol' => 'G',
            ),
            'HNL' => array(
                'name'   => 'Honduran Lempira',
                'symbol' => 'L',
            ),
            'HKD' => array(
                'name'   => 'Hong Kong Dollar',
                'symbol' => '$',
            ),
            'HUF' => array(
                'name'   => 'Hungarian Forint',
                'symbol' => 'Ft',
            ),
            'ISK' => array(
                'name'   => 'Icelandic Króna',
                'symbol' => 'kr',
            ),
            'INR' => array(
                'name'   => 'Indian Rupee',
                'symbol' => '₹',
            ),
            'IDR' => array(
                'name'   => 'Indonesian Rupiah',
                'symbol' => 'Rp',
            ),
            'IRR' => array(
                'name'   => 'Iranian Rial',
                'symbol' => '﷼',
            ),
            'IQD' => array(
                'name'   => 'Iraqi Dinar',
                'symbol' => 'د.ع',
            ),
            'ILS' => array(
                'name'   => 'Israeli New Sheqel',
                'symbol' => '₪',
            ),
            'ITL' => array(
                'name'   => 'Italian Lira',
                'symbol' => 'L,£',
            ),
            'JMD' => array(
                'name'   => 'Jamaican Dollar',
                'symbol' => 'J$',
            ),
            'JPY' => array(
                'name'   => 'Japanese Yen',
                'symbol' => '¥',
            ),
            'JOD' => array(
                'name'   => 'Jordanian Dinar',
                'symbol' => 'ا.د',
            ),
            'KZT' => array(
                'name'   => 'Kazakhstani Tenge',
                'symbol' => 'лв',
            ),
            'KES' => array(
                'name'   => 'Kenyan Shilling',
                'symbol' => 'KSh',
            ),
            'KWD' => array(
                'name'   => 'Kuwaiti Dinar',
                'symbol' => 'ك.د',
            ),
            'KGS' => array(
                'name'   => 'Kyrgystani Som',
                'symbol' => 'лв',
            ),
            'LAK' => array(
                'name'   => 'Laotian Kip',
                'symbol' => '₭',
            ),
            'LVL' => array(
                'name'   => 'Latvian Lats',
                'symbol' => 'Ls',
            ),
            'LBP' => array(
                'name'   => 'Lebanese Pound',
                'symbol' => '£',
            ),
            'LSL' => array(
                'name'   => 'Lesotho Loti',
                'symbol' => 'L',
            ),
            'LRD' => array(
                'name'   => 'Liberian Dollar',
                'symbol' => '$',
            ),
            'LYD' => array(
                'name'   => 'Libyan Dinar',
                'symbol' => 'د.ل',
            ),
            'LTC' => array(
                'name'   => 'Litecoin',
                'symbol' => 'Ł',
            ),
            'LTL' => array(
                'name'   => 'Lithuanian Litas',
                'symbol' => 'Lt',
            ),
            'MOP' => array(
                'name'   => 'Macanese Pataca',
                'symbol' => '$',
            ),
            'MKD' => array(
                'name'   => 'Macedonian Denar',
                'symbol' => 'ден',
            ),
            'MGA' => array(
                'name'   => 'Malagasy Ariary',
                'symbol' => 'Ar',
            ),
            'MWK' => array(
                'name'   => 'Malawian Kwacha',
                'symbol' => 'MK',
            ),
            'MYR' => array(
                'name'   => 'Malaysian Ringgit',
                'symbol' => 'RM',
            ),
            'MVR' => array(
                'name'   => 'Maldivian Rufiyaa',
                'symbol' => 'Rf',
            ),
            'MRO' => array(
                'name'   => 'Mauritanian Ouguiya',
                'symbol' => 'MRU',
            ),
            'MUR' => array(
                'name'   => 'Mauritian Rupee',
                'symbol' => '₨',
            ),
            'MXN' => array(
                'name'   => 'Mexican Peso',
                'symbol' => '$',
            ),
            'MDL' => array(
                'name'   => 'Moldovan Leu',
                'symbol' => 'L',
            ),
            'MNT' => array(
                'name'   => 'Mongolian Tugrik',
                'symbol' => '₮',
            ),
            'MAD' => array(
                'name'   => 'Moroccan Dirham',
                'symbol' => 'MAD',
            ),
            'MZM' => array(
                'name'   => 'Mozambican Metical',
                'symbol' => 'MT',
            ),
            'MMK' => array(
                'name'   => 'Myanmar Kyat',
                'symbol' => 'K',
            ),
            'NAD' => array(
                'name'   => 'Namibian Dollar',
                'symbol' => '$',
            ),
            'NPR' => array(
                'name'   => 'Nepalese Rupee',
                'symbol' => '₨',
            ),
            'ANG' => array(
                'name'   => 'Netherlands Antillean Guilder',
                'symbol' => 'ƒ',
            ),
            'TWD' => array(
                'name'   => 'New Taiwan Dollar',
                'symbol' => '$',
            ),
            'NZD' => array(
                'name'   => 'New Zealand Dollar',
                'symbol' => '$',
            ),
            'NIO' => array(
                'name'   => 'Nicaraguan Córdoba',
                'symbol' => 'C$',
            ),
            'NGN' => array(
                'name'   => 'Nigerian Naira',
                'symbol' => '₦',
            ),
            'KPW' => array(
                'name'   => 'North Korean Won',
                'symbol' => '₩',
            ),
            'NOK' => array(
                'name'   => 'Norwegian Krone',
                'symbol' => 'kr',
            ),
            'OMR' => array(
                'name'   => 'Omani Rial',
                'symbol' => '.ع.ر',
            ),
            'PKR' => array(
                'name'   => 'Pakistani Rupee',
                'symbol' => '₨',
            ),
            'PAB' => array(
                'name'   => 'Panamanian Balboa',
                'symbol' => 'B/.',
            ),
            'PGK' => array(
                'name'   => 'Papua New Guinean Kina',
                'symbol' => 'K',
            ),
            'PYG' => array(
                'name'   => 'Paraguayan Guarani',
                'symbol' => '₲',
            ),
            'PEN' => array(
                'name'   => 'Peruvian Nuevo Sol',
                'symbol' => 'S/.',
            ),
            'PHP' => array(
                'name'   => 'Philippine Peso',
                'symbol' => '₱',
            ),
            'PLN' => array(
                'name'   => 'Polish Zloty',
                'symbol' => 'zł',
            ),
            'QAR' => array(
                'name'   => 'Qatari Rial',
                'symbol' => 'ق.ر',
            ),
            'RON' => array(
                'name'   => 'Romanian Leu',
                'symbol' => 'lei',
            ),
            'RUB' => array(
                'name'   => 'Russian Ruble',
                'symbol' => '₽',
            ),
            'RWF' => array(
                'name'   => 'Rwandan Franc',
                'symbol' => 'FRw',
            ),
            'SVC' => array(
                'name'   => 'Salvadoran Colón',
                'symbol' => '₡',
            ),
            'WST' => array(
                'name'   => 'Samoan Tala',
                'symbol' => 'SAT',
            ),
            'STD' => array(
                'name'   => 'São Tomé and Príncipe Dobra',
                'symbol' => 'Db',
            ),
            'SAR' => array(
                'name'   => 'Saudi Riyal',
                'symbol' => '﷼',
            ),
            'RSD' => array(
                'name'   => 'Serbian Dinar',
                'symbol' => 'din',
            ),
            'SCR' => array(
                'name'   => 'Seychellois Rupee',
                'symbol' => 'SRe',
            ),
            'SLL' => array(
                'name'   => 'Sierra Leonean Leone',
                'symbol' => 'Le',
            ),
            'SGD' => array(
                'name'   => 'Singapore Dollar',
                'symbol' => '$',
            ),
            'SKK' => array(
                'name'   => 'Slovak Koruna',
                'symbol' => 'Sk',
            ),
            'SBD' => array(
                'name'   => 'Solomon Islands Dollar',
                'symbol' => 'Si$',
            ),
            'SOS' => array(
                'name'   => 'Somali Shilling',
                'symbol' => 'Sh.so.',
            ),
            'ZAR' => array(
                'name'   => 'South African Rand',
                'symbol' => 'R',
            ),
            'KRW' => array(
                'name'   => 'South Korean Won',
                'symbol' => '₩',
            ),
            'SSP' => array(
                'name'   => 'South Sudanese Pound',
                'symbol' => '£',
            ),
            'XDR' => array(
                'name'   => 'Special Drawing Rights',
                'symbol' => 'SDR',
            ),
            'LKR' => array(
                'name'   => 'Sri Lankan Rupee',
                'symbol' => 'Rs',
            ),
            'SHP' => array(
                'name'   => 'St. Helena Pound',
                'symbol' => '£',
            ),
            'SDG' => array(
                'name'   => 'Sudanese Pound',
                'symbol' => '.س.ج',
            ),
            'SRD' => array(
                'name'   => 'Surinamese Dollar',
                'symbol' => '$',
            ),
            'SZL' => array(
                'name'   => 'Swazi Lilangeni',
                'symbol' => 'E',
            ),
            'SEK' => array(
                'name'   => 'Swedish Krona',
                'symbol' => 'kr',
            ),
            'CHF' => array(
                'name'   => 'Swiss Franc',
                'symbol' => 'CHf',
            ),
            'SYP' => array(
                'name'   => 'Syrian Pound',
                'symbol' => 'LS',
            ),
            'TJS' => array(
                'name'   => 'Tajikistani Somoni',
                'symbol' => 'SM',
            ),
            'TZS' => array(
                'name'   => 'Tanzanian Shilling',
                'symbol' => 'TSh',
            ),
            'THB' => array(
                'name'   => 'Thai Baht',
                'symbol' => '฿',
            ),
            'TOP' => array(
                'name'   => "Tongan Pa'anga",
                'symbol' => '$',
            ),
            'TTD' => array(
                'name'   => 'Trinidad & Tobago Dollar',
                'symbol' => '$',
            ),
            'TND' => array(
                'name'   => 'Tunisian Dinar',
                'symbol' => 'ت.د',
            ),
            'TRY' => array(
                'name'   => 'Turkish Lira',
                'symbol' => '₺',
            ),
            'TMT' => array(
                'name'   => 'Turkmenistani Manat',
                'symbol' => 'T',
            ),
            'UGX' => array(
                'name'   => 'Ugandan Shilling',
                'symbol' => 'USh',
            ),
            'UAH' => array(
                'name'   => 'Ukrainian Hryvnia',
                'symbol' => '₴',
            ),
            'AED' => array(
                'name'   => 'United Arab Emirates Dirham',
                'symbol' => 'إ.د',
            ),
            'UYU' => array(
                'name'   => 'Uruguayan Peso',
                'symbol' => '$',
            ),
            'USD' => array(
                'name'   => 'US Dollar',
                'symbol' => '$',
            ),
            'UZS' => array(
                'name'   => 'Uzbekistan Som',
                'symbol' => 'лв',
            ),
            'VUV' => array(
                'name'   => 'Vanuatu Vatu',
                'symbol' => 'VT',
            ),
            'VEF' => array(
                'name'   => 'Venezuelan BolÃvar',
                'symbol' => 'Bs',
            ),
            'VND' => array(
                'name'   => 'Vietnamese Dong',
                'symbol' => '₫',
            ),
            'YER' => array(
                'name'   => 'Yemeni Rial',
                'symbol' => '﷼',
            ),
            'ZMK' => array(
                'name'   => 'Zambian Kwacha',
                'symbol' => 'ZK',
            ),
            'ZWL' => array(
                'name'   => 'Zimbabwean dollar',
                'symbol' => '$',
            ),
        );
    }

    /**
     * Get default tracker settings.
     */
    public static function default_currency_settings() {
        return array(
            'currency_position'  => 'left',
            'thousand_separator' => ',',
            'decimal_separator'  => '.',
            'decimals'           => 2,
        );
    }

    /**
     * Validate currency settings.
     *
     * @param array $settings Settings value.
     */
    public static function validate_currency_settings( $settings ) {
        $default        = static::default_currency_settings();
        $valid_settings = array();

        $valid_settings['currency_position'] = isset( $settings['currency_position'] ) ? sanitize_text_field( wp_unslash( $settings['currency_position'] ) ) : 'left';
        if ( ! in_array( $valid_settings['currency_position'], array( 'left', 'right', 'left_space', 'right_space' ), true ) ) {
            $valid_settings['currency_position'] = $default['currency_position'];
        }

        $valid_settings['thousand_separator'] = isset( $settings['thousand_separator'] ) ? sanitize_text_field( wp_unslash( $settings['thousand_separator'] ) ) : $default['thousand_separator'];
        $valid_settings['decimal_separator']  = isset( $settings['decimal_separator'] ) ? sanitize_text_field( wp_unslash( $settings['decimal_separator'] ) ) : $default['decimal_separator'];
        $valid_settings['decimals']           = isset( $settings['decimals'] ) ? intval( $settings['decimals'] ) : $default['decimals'];

        if ( $valid_settings['decimals'] > 8 || $valid_settings['decimals'] < 0 ) {
            $valid_settings['decimals'] = $default['decimals'];
        }

        return $valid_settings;
    }

    /**
     * Get payment method icon
     *
     * Returns FOmantic UI icon for payment selected payment method.
     *
     * @param string $payment_method Selected patyment method.
     */
    public static function get_payment_method_icon( $payment_method ) {
        $icon = '<i class="money bill large icon"></i>';

        switch ( $payment_method ) {
            case 'PayPal':
                $icon = '<span data-tooltip="PayPal" data-inverted="" data-position="left center"><i class="paypal large icon"></i></span>';
                break;
            case 'Stripe':
                $icon = '<span data-tooltip="Stripe" data-inverted="" data-position="left center"><i class="stripe large icon"></i></span>';
                break;
            case 'Apple Pay':
                $icon = '<span data-tooltip="Apple Pay" data-inverted="" data-position="left center"><i class="apple pay large icon"></i></span>';
                break;
            case 'Amazon Pay':
                $icon = '<span data-tooltip="Amazon Pay" data-inverted="" data-position="left center"><i class="amazon pay large icon"></i></span>';
                break;
            case 'Google Pay':
                $icon = '<span data-tooltip="Google Pay" data-inverted="" data-position="left center"><i class="google pay large icon"></i></span>';
                break;
            case 'Credit Card':
                $icon = '<span data-tooltip="Credit Card" data-inverted="" data-position="left center"><i class="credit card large icon"></i></span>';
                break;
            case 'Debit Card':
                $icon = '<span data-tooltip="Debit Card" data-inverted="" data-position="left center"><i class="credit card outline large icon"></i></span>';
                break;
            case 'Cash':
                $icon = '<span data-tooltip="Cash" data-inverted="" data-position="left center"><i class="money bill alternate outline large icon"></i></span>';
                break;
            default:
                break;
        }

        return $icon;
    }


    /**
     * Method get_product_default_icons().
     *
     * @param bool   $get_all Get all icons.
     * @param string $def_type_icon to get default icon for types.
     *
     * @return string icon.
     */
    public static function get_product_default_icons( $get_all = true, $def_type_icon = '' ) {
        unset( $get_all );
        if ( 'default_custom_product_type' === $def_type_icon ) {
            return 'folder open';
        } elseif ( 'default_product' === $def_type_icon ) {
            return 'archive';
        }

        if ( ! empty( $def_type_icon ) ) {
            $default_pro_type_icons = Cost_Tracker_Admin::get_default_product_types_icons();
            if ( isset( $default_pro_type_icons[ $def_type_icon ] ) ) {
                return $default_pro_type_icons[ $def_type_icon ];
            }
        }
        return MainWP_UI::get_default_icons();
    }
}
