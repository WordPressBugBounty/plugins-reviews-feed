import SbUtils from '../../Utils/SbUtils'

const Checkbox = ( props ) => {

    const actionsList =  [ 'onChange', 'onClick' ];

    return (
        <label className='sb-checkbox-ctn' data-disabled={ props?.disabled === true}>
            <input
                type="checkbox"
                checked={ props?.value === props?.enabled }
                disabled={ props?.disabled === true}
                { ...SbUtils.getElementActions( props, actionsList ) }
            />
            <span className='sb-checkbox-deco sb-tr-1'></span>
            {
                props?.label &&
                <span className={ ( props?.labelStrong === true ? 'sb-bold ' : '' ) + 'sb-el-label sb-text-tiny'}>{ props.label }</span>
            }
        </label>
    )

}

export default Checkbox;