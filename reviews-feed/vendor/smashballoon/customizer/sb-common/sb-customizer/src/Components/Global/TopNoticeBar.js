const TopNoticeBar = ( props ) => {
    return (
        props?.active &&
        <div
            className="sb-noticebar-ctn"
            data-type={ props?.type ? props?.type : 'default' }
        >
            <span className="sb-noticebar-heading">
                { props?.heading }
                {
                    props?.actionsList &&
                    props?.actionsList.map((act, actKey) => {
                        return (
                            <span
                                key={actKey}
                                className="sb-noticebar-action"
                                data-style={act?.style ?? 'default'}
                                onClick={ () => {
                                    if (act?.onClick === 'openAddApiKeyModal') {
                                        props?.openAddApiKeyModal(act?.provider)
                                    } else{
                                        if (act?.onClick){
                                            act?.onClick()
                                        }
                                    }
                                }}
                           >
                             {act?.text}
                            </span>
                        )
                    })
                }

                {
                    props?.actionText &&
                    <strong
                        onClick={ () => {
                            props?.actionClick &&
                                props?.actionClick()
                        }}
                    >
                        {props?.actionText}
                    </strong>
                }
            </span>
            {
                (props?.close !== false || props?.close === undefined) &&
                <div
                    className='sb-cls-ctn sb-modal-cls'
                    onClick={ () => {
                        props?.onClose &&
                            props.onClose()
                    }}
                >
                </div>
            }

        </div>
    )
}

export default TopNoticeBar;