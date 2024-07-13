<script src="//ajax.googleapis.com/ajax/libs/jquery/1.11.3/jquery.min.js"></script>

<style>
    input[type=checkbox]{
    height: 0;
    width: 0;
    visibility: hidden;
    }

    .qysmat-lable {
    cursor: pointer;
    text-indent: -9999px;
    width: 32px;
    height: 15px;
    background: rgb(145, 143, 143);
    /* display: block; */
    border-radius: 100px;
    position: relative;
    }

    .qysmat-lable:after {
    content: '';
    position: absolute;
    top: 2.2px;
    left: 3px;
    width: 10px;
    height: 10px;
    background: #fff;
    border-radius: 100px;
    transition: 0.2s;
    }

    input:checked + label {
        background: linear-gradient(180deg, #AF9A7F 0%, #A28D69 50%, #8F7C5C 100%);
    }

    input:checked + label:after {
    left: calc(100% - 5px);
    transform: translateX(-100%);
    }

    .qysmat-lable:active:after {
    width: 100px;
    }

    /* div.dataTables_wrapper div.dataTables_filter label{
        display: none
    } */
</style>

<div class="col-12">
    <div class="card">
       <div class="card-body">
            <div class="table-responsive">
                <table id="zero_config" class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th class="text-center">{{ __('msg.No')}}</th>
                            <th class="text-center">{{ __('msg.Singleton')}}</th>
                            <th class="text-center">{{ __('msg.Parent')}}</th>
                            <th class="text-center">{{ __('msg.Actions')}}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if (!$records->isEmpty())
                            @foreach ($records as $value)
                                <tr>
                                    <td class="text-center">{{$loop->iteration}}</td>
                                    <td>
                                        <a class="d-flex align-items-center text-secondary" href="{{ route('sigletons') }}">
                                            <div class="m-r-10">
                                                <img src="{{ $value->photo1 ? asset($value->photo1) : asset('assets/images/users/no-image.png') }}" alt="user" class="rounded-circle" width="40" height="40">
                                            </div>
                                            <div class="">
                                                <h4 class="m-b-0 font-16">{{ $value->name.' '.$value->lname }}</h4>
                                                <span>{{ $value->email }}</span>
                                            </div>
                                        </a>
                                    </td>
                                    <td>
                                        <a class="d-flex align-items-center text-secondary" href="{{ route('parents') }}">
                                            <div class="m-r-10">
                                                <img src="{{ $value->parent_profile_pic ? asset($value->parent_profile_pic) : asset('assets/images/users/no-image.png') }}" alt="user" class="rounded-circle" width="40" height="40">
                                            </div>
                                            <div class="">
                                                <h4 class="m-b-0 font-16">{{ $value->parent_name.' '.$value->parent_lname }}</h4>
                                                <span>{{ $value->parent_email }}</span>
                                            </div>
                                        </a>
                                    </td>
                                    <td class="text-center bt-switch">
                                        <div class="row justify-content-center">
                                            <div class="col-12">
                                                <form action="{{route('view-joint-singleton')}}" method="post">
                                                    @csrf
                                                    <input type="hidden" value="{{$value->id}}" id="id" name="id" />
                                                    <button type="submit" class="btn btn-lg text-qysmat" onclick="this.form.submit()" data-toggle="tooltip" title='View'> <i class="fas fa-eye"></i> </button>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @else
                            <tr class="text-center">
                                <td colspan="9"><h4>{{ __('msg.No Data Found')}}</h4></td>
                            </tr>
                        @endif
                    </tbody>
                </table>
                {{-- {!!$records->withQueryString()->links('pagination::bootstrap-5')!!} --}}
            </div>
       </div>
    </div>
</div>

<script type="text/javascript">

    $(document).ready(function() {
    // Initialize DataTable
    var table = $('#zero_config').DataTable();
    
    // Set custom placeholder for search input
    var placeholderText = '{{ trans("msg.Search here") }}';
    
    // Find the search input element and set the new placeholder
    var searchInput = $('div.dataTables_wrapper input[type="search"]');
    searchInput.attr('placeholder', placeholderText);
    });
</script>
