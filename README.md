# Simplified Sentry Transactions
 

The Sentry PHP SDK provides a very powerful transactions system that can be used to make your life a whole lot easier, and I greatly recommend using it directly. This file is simply a part of my personal website utilities for when I want to be lazy.

**Please ensure that before using any of the functions in the `SentryPreformance` class, the [Sentry SDK](https://github.com/getsentry/sentry-php) is loaded and initialised.**



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

As you can see, very easy. Starting and terminating a transaction can be completed in only two lines! In your Sentry performance overview you would see something very similar to the image below (with the code above being used).

![Sentry View](https://cdn.morgverd.com/static/github/sentry/DPX09i5KCJAj88MME6tp5bV5T.png)
