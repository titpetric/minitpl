{eval $key="nu"}

{inline item}
{if $i>0}{i}nd{else}first{/if}
{/inline}

{eval $i=1}
{foreach $items as $item}
	{eval $id = $key . $i}
	{inline:item}
	{eval $i++}
{/foreach}
