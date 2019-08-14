# custom_search_filter

## Custom Search Filter for Api Platform

I was looking to add a method for selecting the search strategy automatically in the request to my REST API created using [API Platform](https://api-platform.com/). Specifically I wanted to use a `[strategy]` syntax in the url. 

ex: `/api/staff?lastname[start]=dav`

Being new to both [Symfony 4](https://symfony.com) and Doctrine I was quite stumped. So I did what any good developer does when they can't figure out a solution to a problem, I posted a question to [Stack Overflow](https://stackoverflow.com/questions/54652855/pass-search-strategy-to-filter-from-rest-uri) in hopes of an answer.

No luck.

Eventually I stumbled on a file written by the author of API Platform [Kévin Dunglas](https://dunglas.fr/) which did exactly what I needed. I grabbed the file, added it to my codebase and _voila_ it just worked.

Fast forward a few months and I realised that my Stack Overflow question was still open. I decided to locate the link to that file and post it as the solution. Unfortunately I could not re-locate where I got the file from. Even the copyright notice in the file (which I had retained of course) did not lead me to its location.

I decided to reach out to M. Dunglas via email to relate this story and to ask for the location of the file. He responded to give me permission to post the file as the official solution to this specific use-case. 

This repository exists to do just that. 

## Implementation

- Add the file to the /src/Filter directory of your project
- Use the @ApiFilter annotation to set up the affected fields and the default search strategy `[exact|partial|start|end|word_start]`

Example

```php
/**
 * @ApiFilter(CustomSearchFilter::class, properties={
 *  "status": "partial",
 *  "firstname": "partial",
 *  "lastname": "partial",
 *  "email": "partial",
 *  "position": "partial"
 * })
```

## License

The Copyright block in the file refers to the LICENSE file distributed with the source code. I don't have that file, so here is a link to the official [API Platform LICENSE](https://github.com/api-platform/api-platform/blob/master/LICENSE) file for completeness
