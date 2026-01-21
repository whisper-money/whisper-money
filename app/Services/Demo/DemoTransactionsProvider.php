<?php

namespace App\Services\Demo;

use App\Enums\TransactionSource;
use Carbon\Carbon;

class DemoTransactionsProvider
{
    /**
     * @var array<int, array{description: string, amount_min: int, amount_max: int, category_name: string, frequency: string}>
     */
    private const TRANSACTION_TEMPLATES = [
        ['description' => 'Whole Foods Market', 'amount_min' => -15000, 'amount_max' => -8000, 'category_name' => 'Groceries', 'frequency' => 'weekly'],
        ['description' => 'Trader Joe\'s', 'amount_min' => -8500, 'amount_max' => -4500, 'category_name' => 'Groceries', 'frequency' => 'weekly'],
        ['description' => 'Costco Wholesale', 'amount_min' => -25000, 'amount_max' => -12000, 'category_name' => 'Groceries', 'frequency' => 'monthly'],
        ['description' => 'Safeway Supermarket', 'amount_min' => -12000, 'amount_max' => -6500, 'category_name' => 'Groceries', 'frequency' => 'weekly'],
        ['description' => 'Publix Grocery Store', 'amount_min' => -11000, 'amount_max' => -6000, 'category_name' => 'Groceries', 'frequency' => 'weekly'],
        ['description' => 'Kroger Marketplace', 'amount_min' => -9500, 'amount_max' => -5000, 'category_name' => 'Groceries', 'frequency' => 'weekly'],
        ['description' => 'Aldi Grocery', 'amount_min' => -7500, 'amount_max' => -4000, 'category_name' => 'Groceries', 'frequency' => 'weekly'],
        ['description' => 'Sprouts Farmers Market', 'amount_min' => -10000, 'amount_max' => -5500, 'category_name' => 'Groceries', 'frequency' => 'biweekly'],
        ['description' => 'Starbucks Coffee', 'amount_min' => -850, 'amount_max' => -450, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'frequent'],
        ['description' => 'Dunkin Donuts', 'amount_min' => -650, 'amount_max' => -350, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'frequent'],
        ['description' => 'Peet\'s Coffee', 'amount_min' => -750, 'amount_max' => -400, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'frequent'],
        ['description' => 'Local Coffee Shop', 'amount_min' => -900, 'amount_max' => -500, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'frequent'],
        ['description' => 'Chipotle Mexican Grill', 'amount_min' => -1800, 'amount_max' => -1200, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'weekly'],
        ['description' => 'Olive Garden Restaurant', 'amount_min' => -8500, 'amount_max' => -4500, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly'],
        ['description' => 'Thai Palace Dinner', 'amount_min' => -6500, 'amount_max' => -3500, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly'],
        ['description' => 'Sushi House', 'amount_min' => -7500, 'amount_max' => -4000, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly'],
        ['description' => 'Pizza Hut', 'amount_min' => -3500, 'amount_max' => -1800, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'biweekly'],
        ['description' => 'Domino\'s Pizza', 'amount_min' => -3200, 'amount_max' => -1500, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'biweekly'],
        ['description' => 'Papa John\'s Pizza', 'amount_min' => -3000, 'amount_max' => -1400, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'biweekly'],
        ['description' => 'Red Lobster', 'amount_min' => -9500, 'amount_max' => -5000, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'quarterly'],
        ['description' => 'Outback Steakhouse', 'amount_min' => -8500, 'amount_max' => -4500, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'quarterly'],
        ['description' => 'Applebees', 'amount_min' => -6500, 'amount_max' => -3500, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly'],
        ['description' => 'TGI Fridays', 'amount_min' => -7000, 'amount_max' => -3800, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly'],
        ['description' => 'Buffalo Wild Wings', 'amount_min' => -5500, 'amount_max' => -2800, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly'],
        ['description' => 'Panera Bread', 'amount_min' => -2500, 'amount_max' => -1200, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'weekly'],
        ['description' => 'Subway', 'amount_min' => -1200, 'amount_max' => -700, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'weekly'],
        ['description' => 'McDonald\'s', 'amount_min' => -1000, 'amount_max' => -600, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'weekly'],
        ['description' => 'Burger King', 'amount_min' => -950, 'amount_max' => -550, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'weekly'],
        ['description' => 'Taco Bell', 'amount_min' => -800, 'amount_max' => -450, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'weekly'],
        ['description' => 'Indian Curry House', 'amount_min' => -6000, 'amount_max' => -3200, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly'],
        ['description' => 'Mediterranean Grill', 'amount_min' => -5500, 'amount_max' => -2800, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly'],
        ['description' => 'Chinese Restaurant', 'amount_min' => -5000, 'amount_max' => -2500, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly'],
        ['description' => 'Italian Bistro', 'amount_min' => -7500, 'amount_max' => -4000, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly'],
        ['description' => 'Shell Gas Station', 'amount_min' => -6500, 'amount_max' => -3500, 'category_name' => 'Fuel', 'frequency' => 'biweekly'],
        ['description' => 'Chevron Gas', 'amount_min' => -5800, 'amount_max' => -3200, 'category_name' => 'Fuel', 'frequency' => 'biweekly'],
        ['description' => 'BP Gas Station', 'amount_min' => -6200, 'amount_max' => -3400, 'category_name' => 'Fuel', 'frequency' => 'biweekly'],
        ['description' => 'Exxon Mobil', 'amount_min' => -6000, 'amount_max' => -3300, 'category_name' => 'Fuel', 'frequency' => 'biweekly'],
        ['description' => 'Speedway Gas', 'amount_min' => -5500, 'amount_max' => -3000, 'category_name' => 'Fuel', 'frequency' => 'biweekly'],
        ['description' => 'Salary Deposit - ACME Corp', 'amount_min' => 485000, 'amount_max' => 485000, 'category_name' => 'Salary', 'frequency' => 'monthly'],
        ['description' => 'Freelance Payment - Web Design', 'amount_min' => 25000, 'amount_max' => 75000, 'category_name' => 'Salary', 'frequency' => 'monthly'],
        ['description' => 'Quarterly Bonus', 'amount_min' => 50000, 'amount_max' => 150000, 'category_name' => 'Salary', 'frequency' => 'quarterly'],
        ['description' => 'Electric Company - Monthly Bill', 'amount_min' => -18500, 'amount_max' => -9500, 'category_name' => 'Electricity', 'frequency' => 'monthly'],
        ['description' => 'Water & Sewer Utility', 'amount_min' => -7500, 'amount_max' => -4500, 'category_name' => 'Water', 'frequency' => 'monthly'],
        ['description' => 'Natural Gas Bill', 'amount_min' => -12000, 'amount_max' => -4500, 'category_name' => 'Natural gas', 'frequency' => 'monthly'],
        ['description' => 'Trash Collection Service', 'amount_min' => -3500, 'amount_max' => -2000, 'category_name' => 'Water', 'frequency' => 'monthly'],
        ['description' => 'Comcast Internet & Cable', 'amount_min' => -15999, 'amount_max' => -12999, 'category_name' => 'Telephone, internet, TV, computer', 'frequency' => 'monthly'],
        ['description' => 'T-Mobile Wireless', 'amount_min' => -8500, 'amount_max' => -7500, 'category_name' => 'Telephone, internet, TV, computer', 'frequency' => 'monthly'],
        ['description' => 'Verizon Wireless', 'amount_min' => -9000, 'amount_max' => -8000, 'category_name' => 'Telephone, internet, TV, computer', 'frequency' => 'monthly'],
        ['description' => 'AT&T Mobile', 'amount_min' => -8800, 'amount_max' => -7800, 'category_name' => 'Telephone, internet, TV, computer', 'frequency' => 'monthly'],
        ['description' => 'Spectrum Internet', 'amount_min' => -7999, 'amount_max' => -6999, 'category_name' => 'Telephone, internet, TV, computer', 'frequency' => 'monthly'],
        ['description' => 'Netflix Subscription', 'amount_min' => -1599, 'amount_max' => -1599, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'Spotify Premium', 'amount_min' => -1099, 'amount_max' => -1099, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'Amazon Prime', 'amount_min' => -1499, 'amount_max' => -1499, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'Disney+ Subscription', 'amount_min' => -1099, 'amount_max' => -1099, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'Hulu Subscription', 'amount_min' => -799, 'amount_max' => -799, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'HBO Max', 'amount_min' => -1599, 'amount_max' => -1599, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'Apple Music', 'amount_min' => -1099, 'amount_max' => -1099, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'YouTube Premium', 'amount_min' => -1399, 'amount_max' => -1399, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'Adobe Creative Cloud', 'amount_min' => -5499, 'amount_max' => -5499, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'Microsoft 365', 'amount_min' => -699, 'amount_max' => -699, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'Dropbox Plus', 'amount_min' => -999, 'amount_max' => -999, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'DoorDash Delivery', 'amount_min' => -4500, 'amount_max' => -2500, 'category_name' => 'Food delivery', 'frequency' => 'weekly'],
        ['description' => 'Uber Eats Order', 'amount_min' => -3800, 'amount_max' => -2200, 'category_name' => 'Food delivery', 'frequency' => 'weekly'],
        ['description' => 'Grubhub Delivery', 'amount_min' => -4200, 'amount_max' => -2400, 'category_name' => 'Food delivery', 'frequency' => 'weekly'],
        ['description' => 'Postmates', 'amount_min' => -4000, 'amount_max' => -2300, 'category_name' => 'Food delivery', 'frequency' => 'weekly'],
        ['description' => 'Amazon.com Purchase', 'amount_min' => -15000, 'amount_max' => -2500, 'category_name' => 'Online transactions', 'frequency' => 'weekly'],
        ['description' => 'eBay Purchase', 'amount_min' => -12000, 'amount_max' => -3000, 'category_name' => 'Online transactions', 'frequency' => 'biweekly'],
        ['description' => 'Etsy Order', 'amount_min' => -8000, 'amount_max' => -2000, 'category_name' => 'Online transactions', 'frequency' => 'monthly'],
        ['description' => 'Target Store', 'amount_min' => -12000, 'amount_max' => -3500, 'category_name' => 'Household goods', 'frequency' => 'biweekly'],
        ['description' => 'Walmart Supercenter', 'amount_min' => -8500, 'amount_max' => -2500, 'category_name' => 'Other groceries', 'frequency' => 'biweekly'],
        ['description' => 'Home Depot', 'amount_min' => -15000, 'amount_max' => -4000, 'category_name' => 'Household goods', 'frequency' => 'monthly'],
        ['description' => 'Lowe\'s Home Improvement', 'amount_min' => -14000, 'amount_max' => -3500, 'category_name' => 'Household goods', 'frequency' => 'monthly'],
        ['description' => 'Bed Bath & Beyond', 'amount_min' => -10000, 'amount_max' => -3000, 'category_name' => 'Household goods', 'frequency' => 'quarterly'],
        ['description' => 'IKEA', 'amount_min' => -20000, 'amount_max' => -5000, 'category_name' => 'Household goods', 'frequency' => 'quarterly'],
        ['description' => 'CVS Pharmacy', 'amount_min' => -4500, 'amount_max' => -1500, 'category_name' => 'Health and pharmaceuticals', 'frequency' => 'monthly'],
        ['description' => 'Walgreens', 'amount_min' => -3500, 'amount_max' => -1200, 'category_name' => 'Health and pharmaceuticals', 'frequency' => 'monthly'],
        ['description' => 'Rite Aid Pharmacy', 'amount_min' => -4000, 'amount_max' => -1300, 'category_name' => 'Health and pharmaceuticals', 'frequency' => 'monthly'],
        ['description' => 'Doctor\'s Office Visit', 'amount_min' => -15000, 'amount_max' => -8000, 'category_name' => 'Health and pharmaceuticals', 'frequency' => 'quarterly'],
        ['description' => 'Dentist Appointment', 'amount_min' => -12000, 'amount_max' => -6000, 'category_name' => 'Health and pharmaceuticals', 'frequency' => 'quarterly'],
        ['description' => 'Pharmacy Prescription', 'amount_min' => -5000, 'amount_max' => -2000, 'category_name' => 'Health and pharmaceuticals', 'frequency' => 'monthly'],
        ['description' => 'Planet Fitness Monthly', 'amount_min' => -2499, 'amount_max' => -2499, 'category_name' => 'Sport and sports goods', 'frequency' => 'monthly'],
        ['description' => '24 Hour Fitness', 'amount_min' => -3999, 'amount_max' => -3999, 'category_name' => 'Sport and sports goods', 'frequency' => 'monthly'],
        ['description' => 'LA Fitness Membership', 'amount_min' => -3499, 'amount_max' => -3499, 'category_name' => 'Sport and sports goods', 'frequency' => 'monthly'],
        ['description' => 'Golf Course Green Fees', 'amount_min' => -8500, 'amount_max' => -4500, 'category_name' => 'Sport and sports goods', 'frequency' => 'monthly'],
        ['description' => 'Tennis Court Rental', 'amount_min' => -3500, 'amount_max' => -1500, 'category_name' => 'Sport and sports goods', 'frequency' => 'monthly'],
        ['description' => 'ATM Cash Withdrawal', 'amount_min' => -30000, 'amount_max' => -10000, 'category_name' => 'Cash withdrawal', 'frequency' => 'biweekly'],
        ['description' => 'State Farm Insurance', 'amount_min' => -15800, 'amount_max' => -12500, 'category_name' => 'Insurance', 'frequency' => 'monthly'],
        ['description' => 'Geico Auto Insurance', 'amount_min' => -14500, 'amount_max' => -11000, 'category_name' => 'Insurance', 'frequency' => 'monthly'],
        ['description' => 'Progressive Insurance', 'amount_min' => -15000, 'amount_max' => -12000, 'category_name' => 'Insurance', 'frequency' => 'monthly'],
        ['description' => 'Health Insurance Premium', 'amount_min' => -35000, 'amount_max' => -25000, 'category_name' => 'Insurance', 'frequency' => 'monthly'],
        ['description' => 'Rent Payment', 'amount_min' => -195000, 'amount_max' => -195000, 'category_name' => 'Rent and maintanence', 'frequency' => 'monthly'],
        ['description' => 'Homeowners Association Fee', 'amount_min' => -25000, 'amount_max' => -15000, 'category_name' => 'Rent and maintanence', 'frequency' => 'monthly'],
        ['description' => 'Property Management Fee', 'amount_min' => -12000, 'amount_max' => -8000, 'category_name' => 'Rent and maintanence', 'frequency' => 'monthly'],
        ['description' => 'Uber Ride', 'amount_min' => -3500, 'amount_max' => -1200, 'category_name' => 'Transportation expenses', 'frequency' => 'weekly'],
        ['description' => 'Lyft Ride', 'amount_min' => -2800, 'amount_max' => -1000, 'category_name' => 'Transportation expenses', 'frequency' => 'weekly'],
        ['description' => 'Parking Garage', 'amount_min' => -2500, 'amount_max' => -800, 'category_name' => 'Parking', 'frequency' => 'weekly'],
        ['description' => 'Street Parking Meter', 'amount_min' => -500, 'amount_max' => -200, 'category_name' => 'Parking', 'frequency' => 'weekly'],
        ['description' => 'Toll Road Payment', 'amount_min' => -800, 'amount_max' => -300, 'category_name' => 'Transportation expenses', 'frequency' => 'weekly'],
        ['description' => 'Car Wash', 'amount_min' => -1500, 'amount_max' => -800, 'category_name' => 'Transportation expenses', 'frequency' => 'monthly'],
        ['description' => 'Oil Change Service', 'amount_min' => -4500, 'amount_max' => -2500, 'category_name' => 'Transportation expenses', 'frequency' => 'quarterly'],
        ['description' => 'Auto Repair Shop', 'amount_min' => -25000, 'amount_max' => -8000, 'category_name' => 'Transportation expenses', 'frequency' => 'quarterly'],
        ['description' => 'H&M Clothing', 'amount_min' => -8500, 'amount_max' => -3500, 'category_name' => 'Clothing and shoes', 'frequency' => 'monthly'],
        ['description' => 'Nike Store', 'amount_min' => -15000, 'amount_max' => -6500, 'category_name' => 'Clothing and shoes', 'frequency' => 'quarterly'],
        ['description' => 'Zara Fashion', 'amount_min' => -12000, 'amount_max' => -5000, 'category_name' => 'Clothing and shoes', 'frequency' => 'monthly'],
        ['description' => 'Macy\'s Department Store', 'amount_min' => -18000, 'amount_max' => -8000, 'category_name' => 'Clothing and shoes', 'frequency' => 'quarterly'],
        ['description' => 'Nordstrom', 'amount_min' => -25000, 'amount_max' => -12000, 'category_name' => 'Clothing and shoes', 'frequency' => 'quarterly'],
        ['description' => 'Adidas Store', 'amount_min' => -13000, 'amount_max' => -6000, 'category_name' => 'Clothing and shoes', 'frequency' => 'quarterly'],
        ['description' => 'AMC Movie Theater', 'amount_min' => -3500, 'amount_max' => -1500, 'category_name' => 'Theatre, music, cinema', 'frequency' => 'monthly'],
        ['description' => 'Regal Cinemas', 'amount_min' => -3200, 'amount_max' => -1400, 'category_name' => 'Theatre, music, cinema', 'frequency' => 'monthly'],
        ['description' => 'Concert Tickets', 'amount_min' => -15000, 'amount_max' => -6000, 'category_name' => 'Theatre, music, cinema', 'frequency' => 'quarterly'],
        ['description' => 'Theater Show Tickets', 'amount_min' => -12000, 'amount_max' => -5000, 'category_name' => 'Theatre, music, cinema', 'frequency' => 'quarterly'],
        ['description' => 'Barnes & Noble Books', 'amount_min' => -4500, 'amount_max' => -1500, 'category_name' => 'Books, newspapers, magazines', 'frequency' => 'monthly'],
        ['description' => 'Kindle Book Purchase', 'amount_min' => -1299, 'amount_max' => -599, 'category_name' => 'Books, newspapers, magazines', 'frequency' => 'monthly'],
        ['description' => 'New York Times Subscription', 'amount_min' => -1799, 'amount_max' => -1799, 'category_name' => 'Books, newspapers, magazines', 'frequency' => 'monthly'],
        ['description' => 'Magazine Subscription', 'amount_min' => -999, 'amount_max' => -499, 'category_name' => 'Books, newspapers, magazines', 'frequency' => 'monthly'],
        ['description' => 'Haircut & Styling', 'amount_min' => -3500, 'amount_max' => -1500, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
        ['description' => 'Barbershop', 'amount_min' => -2500, 'amount_max' => -1000, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
        ['description' => 'Nail Salon', 'amount_min' => -4500, 'amount_max' => -2000, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
        ['description' => 'Dry Cleaning', 'amount_min' => -2500, 'amount_max' => -1200, 'category_name' => 'Other personal transfers', 'frequency' => 'biweekly'],
        ['description' => 'Laundromat', 'amount_min' => -1200, 'amount_max' => -600, 'category_name' => 'Other personal transfers', 'frequency' => 'weekly'],
        ['description' => 'Pet Store - Petco', 'amount_min' => -8500, 'amount_max' => -3500, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
        ['description' => 'Veterinary Visit', 'amount_min' => -12000, 'amount_max' => -6000, 'category_name' => 'Other personal transfers', 'frequency' => 'quarterly'],
        ['description' => 'Pet Grooming', 'amount_min' => -5500, 'amount_max' => -2500, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
        ['description' => 'Starbucks Gift Card', 'amount_min' => -5000, 'amount_max' => -2500, 'category_name' => 'Other personal transfers', 'frequency' => 'quarterly'],
        ['description' => 'Amazon Gift Card', 'amount_min' => -10000, 'amount_max' => -5000, 'category_name' => 'Other personal transfers', 'frequency' => 'quarterly'],
        ['description' => 'Charity Donation', 'amount_min' => -5000, 'amount_max' => -2000, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
        ['description' => 'Interest Payment', 'amount_min' => 250, 'amount_max' => 850, 'category_name' => 'Other incoming payments', 'frequency' => 'monthly'],
        ['description' => 'Dividend - VTI ETF', 'amount_min' => 15000, 'amount_max' => 25000, 'category_name' => 'Other incoming payments', 'frequency' => 'quarterly'],
        ['description' => 'Dividend - Apple Stock', 'amount_min' => 5000, 'amount_max' => 12000, 'category_name' => 'Other incoming payments', 'frequency' => 'quarterly'],
        ['description' => 'Dividend - Microsoft Stock', 'amount_min' => 4500, 'amount_max' => 10000, 'category_name' => 'Other incoming payments', 'frequency' => 'quarterly'],
        ['description' => 'Tax Refund', 'amount_min' => 50000, 'amount_max' => 200000, 'category_name' => 'Other incoming payments', 'frequency' => 'yearly'],
        ['description' => 'Transfer to Savings', 'amount_min' => -50000, 'amount_max' => -25000, 'category_name' => 'Own account', 'frequency' => 'monthly'],
        ['description' => 'Transfer from Savings', 'amount_min' => 30000, 'amount_max' => 80000, 'category_name' => 'Own account', 'frequency' => 'quarterly'],
        ['description' => 'Birthday Gift from Mom', 'amount_min' => 10000, 'amount_max' => 25000, 'category_name' => 'From account of relatives', 'frequency' => 'yearly'],
        ['description' => 'Christmas Gift from Parents', 'amount_min' => 15000, 'amount_max' => 30000, 'category_name' => 'From account of relatives', 'frequency' => 'yearly'],
        ['description' => 'Venmo from Friend', 'amount_min' => 2000, 'amount_max' => 8000, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
        ['description' => 'PayPal Payment Received', 'amount_min' => 5000, 'amount_max' => 15000, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
        ['description' => 'Zelle Transfer Received', 'amount_min' => 3000, 'amount_max' => 10000, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
        ['description' => 'Cash App Payment', 'amount_min' => 1500, 'amount_max' => 6000, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
        ['description' => 'Venmo Payment Sent', 'amount_min' => -5000, 'amount_max' => -2000, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
        ['description' => 'PayPal Payment Sent', 'amount_min' => -8000, 'amount_max' => -3000, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
        ['description' => 'Zelle Transfer Sent', 'amount_min' => -6000, 'amount_max' => -2500, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
    ];

    /**
     * Generate 12 months of realistic transactions.
     *
     * @return array<int, array{description: string, transaction_date: string, amount: int, currency_code: string, notes: string|null, notes_iv: string|null, source: TransactionSource, category_name: string}>
     */
    public function getTransactions(): array
    {
        $transactions = [];
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subMonths(12);

        foreach (self::TRANSACTION_TEMPLATES as $template) {
            $dates = $this->generateDatesForFrequency($template['frequency'], $startDate, $endDate);

            foreach ($dates as $date) {
                $amount = $template['amount_min'] === $template['amount_max']
                    ? $template['amount_min']
                    : rand($template['amount_min'], $template['amount_max']);

                $transactions[] = [
                    'description' => $template['description'],
                    'transaction_date' => $date->format('Y-m-d'),
                    'amount' => $amount,
                    'currency_code' => 'USD',
                    'notes' => null,
                    'notes_iv' => null,
                    'source' => TransactionSource::ManuallyCreated,
                    'category_name' => $template['category_name'],
                ];
            }
        }

        usort($transactions, fn ($a, $b) => strcmp($b['transaction_date'], $a['transaction_date']));

        return $transactions;
    }

    /**
     * @return array<int, Carbon>
     */
    private function generateDatesForFrequency(string $frequency, Carbon $startDate, Carbon $endDate): array
    {
        $dates = [];
        $current = $startDate->copy();

        switch ($frequency) {
            case 'frequent':
                while ($current->lte($endDate)) {
                    if (rand(1, 100) <= 40) {
                        $dates[] = $current->copy()->addHours(rand(8, 20));
                    }
                    $current->addDays(rand(2, 4));
                }
                break;

            case 'weekly':
                while ($current->lte($endDate)) {
                    $dates[] = $current->copy()->addDays(rand(0, 2))->addHours(rand(8, 20));
                    $current->addWeek();
                }
                break;

            case 'biweekly':
                while ($current->lte($endDate)) {
                    $dates[] = $current->copy()->addDays(rand(0, 3))->addHours(rand(8, 20));
                    $current->addWeeks(2);
                }
                break;

            case 'monthly':
                while ($current->lte($endDate)) {
                    $dayOfMonth = min($current->daysInMonth, rand(1, 28));
                    $dates[] = $current->copy()->day($dayOfMonth)->addHours(rand(8, 20));
                    $current->addMonth();
                }
                break;

            case 'quarterly':
                while ($current->lte($endDate)) {
                    $dates[] = $current->copy()->addDays(rand(0, 14))->addHours(rand(8, 20));
                    $current->addMonths(3);
                }
                break;

            case 'yearly':
                $dates[] = $startDate->copy()->addMonths(rand(0, 11))->addDays(rand(0, 28));
                break;
        }

        return array_filter($dates, fn ($date) => $date->lte($endDate) && $date->gte($startDate));
    }
}
