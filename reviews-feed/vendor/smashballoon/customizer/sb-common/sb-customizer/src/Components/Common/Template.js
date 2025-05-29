import SbUtils from '../../Utils/SbUtils'

const Template = ( props ) => {

    return (
        <div
            className={ 'sb-template-item' + (props?.customClass ? ' '+props?.customClass : '') }
            data-checked={props.isChecked}
            data-ispro={props.isTemplatePro}
            onClick={ () => {
                if( props?.onClick )
                    props.onClick()
            }}
        >
            <div className='sb-template-icon sb-fs'>
                { SbUtils.printIcon( props.type + '-template', 'sb-template-svg' ) }
            </div>
            <div className='sb-template-name sb-bold sb-text-small'>
                { props.title }
                {
                    props.isTemplatePro &&
                    SbUtils.printIcon( 'rocket', 'sb-template-name-svg', false, 12 )
                }
            </div>
        </div>
    )
}

export default Template;
