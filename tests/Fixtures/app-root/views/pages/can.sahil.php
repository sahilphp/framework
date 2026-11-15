<!doctype html>
<html>
<body>
    @can('manage-users')
        <p>you can manage</p>
    @endcan
    @cannot('manage-users')
        <p>cannot manage</p>
    @endcannot
    @can('delete-post', 1)
        <p>can delete post 1</p>
    @endcan
    @cannot('delete-post', 2)
        <p>cannot delete post 2</p>
    @endcannot
    @can('delete-post', 2)
        <p>can delete post 2</p>
    @endcan
    <p>expr={{ can('manage-users') ? 'Admin' : 'User' }}</p>
</body>
</html>
