# What makes it amazing code?
- Used Repository pattern
- Code is very descriptive and readable.
- Used proper function commenting explaing the return type and parameters
- create seprate files for the defining helper function
- used protected functions
- follow proper naming noms like variables/classes are in camel case.
- eager loading is used for laravel relations which remove the n+1 problem.

# What makes it Okay code?
- naming conventions are not followed in the whole code.
- DRY rule is not followed at some function like getAll function.
- function commenting the not used in the whole code its missing for some function.
- in some function joins are used but these can be replace by the laravel relations.
- excessive if conditions. at some places if condition can be replaced by ternary operaters/null coalesce/empty() function
- some variables are declared in the if block and return outside the if block.
- some validations can be seprated to seprate request file.

# What makes it horrible code?
- __() function is not used for the strings which are in other laguage. if in future client ask for localization then it will be pain in the neck.
- some function are very long thus complex and code init in repetive which can be fixed by declaring the seprate function.
- DRY rule is ingnored in some function and that is why it make some function very long and complex
- code formating is not done in some function.

# How would you have done it?
- formet code
- used laravel lang function __() for all the strings and used wild card for the dynamic variable in the string.
- ternary operaters/null coalesce/empty() functions and make the code short and simple
- used private functions to seprate some long function logic and make the function more readable and simple.
- try to use the DRY rule where not followed.
- convert all the variable to camel case and make the single nome for all the code
- add function commenting for some functions
- declared out of scope variables to prevent errors
- used laravel eloquent relation ships and its "when" method to prevent excessive use of if conditions
- limit the db cols for the some relations using constraints in the relations ships.
 

