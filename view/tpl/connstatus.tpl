<i class="fa fa-fw fa-comments oneway-overlay text-{{if $perminfo.connpermcount == 3}}success{{elseif $perminfo.connpermcount > 0}}warning{{else}}danger{{/if}}" title="{{$perminfo.connperms}}"></i>
