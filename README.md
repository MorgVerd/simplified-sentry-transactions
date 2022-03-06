# Simplified Sentry Transactions

The Sentry PHP SDK provides a very powerful transactions system that can be used to make your life a whole lot easier, and I greatly recommend using it directly. This file is simply a part of my website utilities for when I want to be lazy.

**Does not support nested Transactions due to the way I originally designed my website API. This file is purely for my performingwebsite, If you choose to use it that's fine, but know that it is somewhat restricted. Please ensure that before using any of the functions in the `SentryPreformance` class, the [Sentry SDK](https://github.com/getsentry/sentry-php) is loaded and initialised.**



Here's an example of a large transaction that includes five sub-spans.

```php

// First, create the actual transaction. The first represents the name of the transaction, and the
// second is the operation. Operations are very useful for grouping multiple transactions together.
$transaction = SentryPreformance::getNewTransaction("Intensive Requests", "intensive.operation");

for ($i=0; $i < 5; $i++) {
    
    // Create a new span within the transaction to represent this specific request.
    $requestSpan = $transaction->createSpan("http.request", "Request #". $i);
    
    // Here, imagine that you are preforming some form of very intensive API request or something.
    // (If you're ever having one second API calls you need to re-think your request pipeline).
    sleep(1);
    
    // Finally, once the intensive operation has finished we need to close the span that was created
    // to represent the request. To ensure that the Transaction is restored correctly we do this directly
    // on the created transaction.
    $transaction->finishSpan($requestSpan);
    
}

// Finally, once all of the requests have finished we simply finish the transaction!
$transaction->finish();
```

As you can see, very easy. Starting and terminating a transaction can be completed in only two lines! In your Sentry performance overview, you would see something very similar to the image below (with the code above being used).

![Sentry View](https://cdn.morgverd.com/static/github/sentry/DPX09i5KCJAj88MME6tp5bV5T.png)



## Dynamic Scoped Spans

Sometimes, a function can be run multiple times in different situations, in which case you may want to allow that function to add a span to the current transaction if there is one. In the case of my website, I use this for generic utilities such as the requests handler which is called in many different contexts. Because of this, adding spans dynamically is very easy.

```php
// Here, we use the new PHP8 null safe operator. This allows us to either interact with the gathered transaction
// if one is set, or do nothing if there is no transaction and the return value is null.
$span = SentryPreformance::getCurrentTransaction()?->createSpan("http.request", "Issue a request to ....");

// Here we would actually perform the expensive API call...

// Finally, we have to finish the created span. For that, we can simply use the finishSpan method. This method will
// finish the span in the current transaction space. However, it also accepts null as the span in case there was never
// any current transaction to begin with.
SentryPreformance::finishSpan($span);

// OR
if ($span !== null) {
	SentryPreformance::getCurrentTransaction()?->finishSpan($span)
}
```



## Measure Wrapper

All `EasySentryTransactions` support inline functional measurement quite easily. You can simply use:

```php
$transaction = SentryPreformance::getNewTransaction("Load all utilities", "files.load");

// Obviously this is just an example, but this is to demonstrate that measurements can be taken multiple times inside
// of a single transaction quite simply.
foreach($utilityFiles as $file) {
	if (!$transaction->measure("Load ".$file, "file.load", function(string $file) {
        return load_my_utility($file);
    }, [$file])) {
        
        // Since the measure function returns the output of the callable we can perform additional logic here,
        // in this example we could use this to handle failed includes etc!
        utility_failed($file);       
    }
}
```

As shown, the measure function will automatically create and then finish the span for you. The callable return value is also passed through so you can use the measure function inline if you wanted to.

## Automatic Shutdown Finishing

In the event of a shutdown, the current transaction is automatically finished to ensure that it is still sent. If you would like to disable this functionality you can either set the default class variable to false in the `EasySentryTransaction` class; Or manually disable it for each spawned transaction by using `$transaction->autoFinishOnShutdown = false`.

