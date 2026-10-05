<?php

namespace App\Domain\Finance;

/**
 * Starter categories, merchants and Hinglish/Hindi aliases copied to each user at provisioning.
 * Aliases are matched after Text::normalize(), so case/punctuation do not matter. An alias must be
 * unique per entity type within this catalog (enforced by a test).
 */
final class DefaultCatalog
{
    /**
     * @return array{expense: array<string, array>, income: array<string, array>}
     *                                                                            Each node: ['aliases' => string[], 'children' => array<string, node>]
     */
    public static function categories(): array
    {
        return [
            'expense' => [
                'Food' => ['aliases' => ['food', 'khana', 'खाना'], 'children' => [
                    'Groceries' => ['aliases' => ['grocery', 'groceries', 'kirana', 'किराना', 'ration', 'राशन', 'milk', 'doodh', 'दूध', 'atta']],
                    'Vegetables' => ['aliases' => ['vegetables', 'vegetable', 'veg', 'sabji', 'sabzi', 'subzi', 'सब्जी', 'सब्ज़ी']],
                    'Fruits' => ['aliases' => ['fruits', 'fruit', 'phal', 'फल']],
                    'Restaurant' => ['aliases' => ['restaurant', 'dining', 'dine out', 'eating out', 'lunch', 'dinner', 'breakfast']],
                    'Fast Food' => ['aliases' => ['fast food', 'snacks', 'snack', 'pizza', 'burger', 'samosa', 'maggi']],
                    'Tea/Coffee' => ['aliases' => ['tea', 'chai', 'चाय', 'coffee', 'cafe']],
                ]],
                'Transport' => ['aliases' => ['transport', 'commute'], 'children' => [
                    'Fuel' => ['aliases' => ['fuel', 'petrol', 'पेट्रोल', 'diesel', 'cng']],
                    'Cab' => ['aliases' => ['cab', 'taxi', 'uber', 'ola', 'rapido']],
                    'Auto' => ['aliases' => ['auto', 'rickshaw', 'rikshaw']],
                    'Bus' => ['aliases' => ['bus']],
                    'Train' => ['aliases' => ['train', 'metro', 'local train']],
                    'Parking & Tolls' => ['aliases' => ['parking', 'toll', 'fastag']],
                ]],
                'Shopping' => ['aliases' => ['shopping'], 'children' => [
                    'Clothes' => ['aliases' => ['clothes', 'clothing', 'kapde', 'कपड़े', 'shoes', 'footwear']],
                    'Electronics' => ['aliases' => ['electronics', 'gadget', 'gadgets']],
                ]],
                'Medical' => ['aliases' => ['medical', 'medicine', 'medicines', 'dawai', 'दवाई', 'doctor', 'hospital', 'pharmacy']],
                'Education' => ['aliases' => ['education', 'school', 'tuition', 'books', 'course']],
                'Utilities' => ['aliases' => ['utilities', 'bills', 'bill'], 'children' => [
                    'Electricity' => ['aliases' => ['electricity', 'bijli', 'बिजली', 'light bill', 'current bill']],
                    'Water' => ['aliases' => ['water', 'paani', 'पानी']],
                    'Internet' => ['aliases' => ['internet', 'wifi', 'broadband']],
                    'Mobile' => ['aliases' => ['mobile', 'recharge', 'phone recharge', 'prepaid', 'postpaid']],
                    'Gas' => ['aliases' => ['gas', 'lpg', 'cylinder']],
                    'DTH' => ['aliases' => ['dth', 'tv recharge', 'cable']],
                ]],
                'Rent' => ['aliases' => ['rent', 'kiraya', 'किराया']],
                'Insurance' => ['aliases' => ['insurance', 'premium', 'lic']],
                'Entertainment' => ['aliases' => ['entertainment', 'movie', 'movies', 'cinema', 'games']],
                'Subscription' => ['aliases' => ['subscription', 'subscriptions']],
                'Travel' => ['aliases' => ['travel', 'trip', 'flight', 'holiday', 'vacation']],
                'Household' => ['aliases' => ['household', 'ghar ka kharcha', 'घर का खर्चा', 'maid', 'repair']],
                'Gifts & Donations' => ['aliases' => ['gift', 'gifts', 'donation', 'daan']],
                'Bank Charges' => ['aliases' => ['bank charges', 'bank charge', 'late fee']],
                'Loan Interest' => ['aliases' => ['loan interest', 'interest paid']],
                'Miscellaneous' => ['aliases' => ['misc', 'miscellaneous', 'other', 'others']],
            ],
            'income' => [
                'Salary' => ['aliases' => ['salary', 'तनख्वाह', 'pagar', 'payslip']],
                'Freelance' => ['aliases' => ['freelance', 'freelancing', 'client payment']],
                'Bonus' => ['aliases' => ['bonus']],
                'Interest' => ['aliases' => ['interest', 'byaj', 'ब्याज']],
                'Refund' => ['aliases' => ['refund']],
                'Cashback' => ['aliases' => ['cashback', 'cash back']],
                'Gift Received' => ['aliases' => ['gift received']],
                'Other Income' => ['aliases' => ['other income']],
            ],
        ];
    }

    /**
     * @return array<string, array{default_category: string, aliases: string[]}>
     */
    public static function merchants(): array
    {
        return [
            'Amazon' => ['default_category' => 'Shopping', 'aliases' => ['amzn', 'amazon pay']],
            'Flipkart' => ['default_category' => 'Shopping', 'aliases' => ['fk']],
            'Myntra' => ['default_category' => 'Clothes', 'aliases' => []],
            'Swiggy' => ['default_category' => 'Restaurant', 'aliases' => ['swiggy instamart']],
            'Zomato' => ['default_category' => 'Restaurant', 'aliases' => []],
            'Uber' => ['default_category' => 'Cab', 'aliases' => []],
            'Ola' => ['default_category' => 'Cab', 'aliases' => ['ola cabs']],
            'Netflix' => ['default_category' => 'Subscription', 'aliases' => []],
            'Spotify' => ['default_category' => 'Subscription', 'aliases' => []],
            'Jio' => ['default_category' => 'Mobile', 'aliases' => ['reliance jio']],
            'Airtel' => ['default_category' => 'Mobile', 'aliases' => []],
            'BigBasket' => ['default_category' => 'Groceries', 'aliases' => ['big basket']],
            'Blinkit' => ['default_category' => 'Groceries', 'aliases' => ['grofers']],
            'Zepto' => ['default_category' => 'Groceries', 'aliases' => []],
            'DMart' => ['default_category' => 'Groceries', 'aliases' => ['d mart']],
            'Reliance Fresh' => ['default_category' => 'Groceries', 'aliases' => []],
            'IRCTC' => ['default_category' => 'Train', 'aliases' => []],
        ];
    }
}
