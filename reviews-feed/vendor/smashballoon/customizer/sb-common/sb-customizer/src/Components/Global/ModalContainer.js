import SbUtils from '../../Utils/SbUtils'

const ModalContainer = ( props ) => {
    const   attributesList = [
                { 'size' : 'medium'},
                'type'
            ];

    return (
        <section
            className='sb-modal-ctn'
            { ...SbUtils.getElementAttributes( props, attributesList ) }
        >
            <div className='sb-modal-insider'>
                {
                    props.closebutton &&
                    <div
                        className='sb-cls-ctn sb-modal-cls'
                        onClick={ () => {
                            props?.onClose &&
                                props.onClose()
                        }}
                    >
                    </div>
                }
                { props.children }
            </div>
        </section>
    );
}
export default ModalContainer;